#!/usr/bin/env bash
# FM Playlist Pi Setup / Update
# Usage: curl -fsSL https://fmplaylist.com/pi/setup.sh | sudo bash -s -- YOUR_TOKEN
set -Eeuo pipefail

# Served through PiSetupController, which rewrites this line to the requesting
# host. Keep it on one line, exactly this shape — the controller matches it.
BASE_URL="https://fmplaylist.com"
FMPLAYLIST_FORCE_REBUILD="${FMPLAYLIST_FORCE_REBUILD:-0}"
APT_LOCK_TIMEOUT="${APT_LOCK_TIMEOUT:-600}"
# git is gone: the payload now comes from the server that serves this script, so
# the version the Pi runs and the version the server reports are the same thing
# by construction. GitHub is no longer a runtime dependency of an update.
APT_PACKAGES=(curl ffmpeg build-essential python3 python3-requests libsndfile1-dev espeak)

# Never let a package's post-install prompt block an unattended run.
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a

# Absolute path to this script, so the detached postinstall run can re-invoke it.
SCRIPT_SELF="$(readlink -f "${BASH_SOURCE[0]}" 2>/dev/null || true)"
if [ -z "$SCRIPT_SELF" ] || [ ! -f "$SCRIPT_SELF" ]; then
  # Piped from curl: there is no file to re-exec, so persist a copy.
  SCRIPT_SELF="/usr/local/lib/fmplaylist-setup.sh"
fi

MODE="install"
SOURCE_HASH=""
case "${1:-}" in
  --postinstall) MODE="postinstall"; SOURCE_HASH="${2:-}" ;;
  --rollback)    MODE="rollback" ;;
esac

if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR: run through sudo so system packages and systemd can be installed."
  exit 1
fi

TOKEN=""
if [ "$MODE" = "install" ]; then
  TOKEN="${1:-}"
  if [ -z "$TOKEN" ]; then
    echo "ERROR: pass your Pi token as an argument."
    echo "  curl -fsSL $BASE_URL/pi/setup.sh | sudo bash -s -- YOUR_TOKEN"
    exit 1
  fi
fi

# Resolving the install user has to survive a detached run (systemd-run, cron,
# nohup), where neither SUDO_USER nor logname exists. Every lookup is guarded:
# under `set -e` + pipefail, one `getent` miss on a nonexistent account would
# otherwise abort the whole script before it printed anything useful.
user_exists() {
  [ -n "${1:-}" ] && getent passwd "$1" >/dev/null 2>&1
}

REAL_USER="${FMPLAYLIST_USER:-${SUDO_USER:-}}"
if ! user_exists "$REAL_USER"; then
  REAL_USER="$(logname 2>/dev/null || true)"
fi
if ! user_exists "$REAL_USER"; then
  # First regular login account — 'dj', 'pi', whatever this image was set up with.
  REAL_USER="$(getent passwd 1000 2>/dev/null | cut -d: -f1 || true)"
fi
if ! user_exists "$REAL_USER"; then
  echo "ERROR: could not determine which user to install for."
  echo "       Re-run with an explicit user, e.g. FMPLAYLIST_USER=pi"
  exit 1
fi

HOME_DIR="$(getent passwd "$REAL_USER" 2>/dev/null | cut -d: -f6 || true)"
HOME_DIR="${HOME_DIR:-/home/$REAL_USER}"
PI_DIR="$HOME_DIR/PiFmRds"
DIR="$PI_DIR/src"
STATE_DIR="$PI_DIR/.install-state"
NATIVE_SHA_FILE="$STATE_DIR/native.sha256"
SOURCE_MANIFEST_FILE="$STATE_DIR/source-files.txt"
SVC="/etc/systemd/system/fmplaylist.service"

WORK_DIR="$(mktemp -d /tmp/fmplaylist-setup.XXXXXX)"
cleanup() {
  rm -rf "$WORK_DIR"
}
trap cleanup EXIT

LAST_GOOD_DIR="$PI_DIR/.last-good"

# Update outcome, read back by the daemon and reported to the admin UI. Written
# to /etc so it survives the source sweep in $DIR.
# Never allowed to fail the caller: this only records what happened. A
# read-only /etc or a full disk must not turn a successful rollback into a
# reported failure.
record_update() {
  local result="$1" message="$2"
  {
    mkdir -p /etc/fmplaylist && chmod 700 /etc/fmplaylist &&
    cat > /etc/fmplaylist/last-update.json <<JSON
{
  "result": "$result",
  "hash": "${SOURCE_HASH:-}",
  "at": "$(date -Is 2>/dev/null || date)",
  "message": "$message"
}
JSON
    chmod 600 /etc/fmplaylist/last-update.json
  } 2>/dev/null || echo "==> WARNING: could not write /etc/fmplaylist/last-update.json"
  return 0
}

# Restart the daemon and prove the NEW one is healthy.
#
# The status file must be removed first. It is written by the daemon that is
# about to be replaced, and it says fm_running:true — so a health check that
# reads whatever is on disk passes instantly and would happily bless a build
# that crash-loops seconds later, silently disabling rollback entirely.
restart_and_verify() {
  rm -f "$DIR/status.json"
  HEALTH_SINCE="$(date +%s)"
  systemctl restart fmplaylist
  run_health_check
}

# Healthy means more than "systemd says active" — a daemon that starts and then
# fails to key the transmitter is exactly the regression worth rolling back.
# The scheduler writes status.json on its first tick, within seconds of boot.
run_health_check() {
  local deadline=$((SECONDS + 120)) status_file="$DIR/status.json" healthy=""
  local since="${HEALTH_SINCE:-0}"

  echo "==> Waiting for daemon to come up healthy..."
  while [ "$SECONDS" -lt "$deadline" ]; do
    if systemctl is-active --quiet fmplaylist; then
      # Second guard behind the rm above: even if the delete failed (read-only
      # fs, permissions), a status file older than the restart proves nothing
      # about the version we just installed.
      healthy="$(python3 -c '
import json, os, sys
path, since = sys.argv[1], float(sys.argv[2])
try:
    if os.path.getmtime(path) < since:
        print("")
    else:
        with open(path) as f:
            data = json.load(f)
        # An admin who deliberately took this Pi off air has not made the
        # update unhealthy; rolling back on that would be wrong.
        print("1" if (data.get("fm_running") or data.get("fm_suppressed")) else "")
except Exception:
    print("")
' "$status_file" "$since" 2>/dev/null || true)"
      if [ -n "$healthy" ]; then
        echo "==> Healthy: service active and transmitting."
        return 0
      fi
    fi
    sleep 3
  done

  echo "ERROR: daemon did not report a running transmitter within 120s."
  journalctl -u fmplaylist -n 40 --no-pager || true
  return 1
}

# Restore the pre-update code + binary. Media in $DIR is never touched, so
# songs and commercials survive a rollback untouched.
rollback_install() {
  if [ ! -d "$LAST_GOOD_DIR" ]; then
    echo "ERROR: no snapshot at $LAST_GOOD_DIR — cannot roll back."
    return 1
  fi

  echo "==> Rolling back to previous install..."
  systemctl stop fmplaylist >/dev/null 2>&1 || true

  local name
  for path in "$LAST_GOOD_DIR"/*; do
    [ -f "$path" ] || continue
    name="$(basename "$path")"
    [ "$name" = "source-files.txt" ] && continue
    [ "$name" = "native.sha256" ] && continue
    cp -f "$path" "$DIR/$name"
  done
  [ -f "$LAST_GOOD_DIR/source-files.txt" ] && cp -f "$LAST_GOOD_DIR/source-files.txt" "$SOURCE_MANIFEST_FILE" || true
  [ -f "$LAST_GOOD_DIR/native.sha256" ] && cp -f "$LAST_GOOD_DIR/native.sha256" "$NATIVE_SHA_FILE" || true
  chmod +x "$DIR/pi_fm_rds" 2>/dev/null || true
  chmod +x "$DIR/run.sh" "$DIR/wifi_apply.sh" "$DIR/whisper.sh" 2>/dev/null || true

  systemctl start fmplaylist >/dev/null 2>&1 || true
  echo "==> Rollback complete."
  return 0
}

native_checksum() {
  local source_dir="$1"
  (
    cd "$source_dir"
    find . -maxdepth 1 -type f \( \
      -name 'Makefile' -o \
      -name '*.c' -o \
      -name '*.h' -o \
      -name 'generate_*.py' -o \
      -name 'pulses.wav' -o \
      -name 'waveforms.c' -o \
      -name 'waveforms.h' \
    \) -print | sort | xargs sha256sum
  ) | sha256sum | awk '{print $1}'
}

# ── Non-install modes ────────────────────────────────────────────────────────
# Both run detached (systemd-run) so they survive the daemon restart they cause.

if [ "$MODE" = "postinstall" ]; then
  if restart_and_verify; then
    record_update "ok" "installed and healthy"
    exit 0
  fi
  if rollback_install && restart_and_verify; then
    record_update "rolled_back" "update failed health check; previous version restored"
    exit 1
  fi
  record_update "failed" "update failed and rollback did not recover"
  exit 1
fi

if [ "$MODE" = "rollback" ]; then
  if rollback_install && restart_and_verify; then
    record_update "rolled_back" "manual rollback"
    exit 0
  fi
  record_update "failed" "manual rollback did not recover"
  exit 1
fi

# A fresh Pi frequently has apt busy or half-configured on first boot:
# unattended-upgrades holds the dpkg lock, or a previous apt run was killed
# (Ctrl-C, power cut, SSH drop) leaving "dpkg was interrupted". Both make a
# one-shot `apt-get install` fail, so wait the lock out and self-repair first.
apt_wait_for_lock() {
  local waited=0
  while pgrep -x apt-get >/dev/null 2>&1 \
    || pgrep -x apt >/dev/null 2>&1 \
    || pgrep -x dpkg >/dev/null 2>&1 \
    || pgrep -x unattended-upgr >/dev/null 2>&1; do
    if [ "$waited" -ge "$APT_LOCK_TIMEOUT" ]; then
      echo "==> apt still busy after ${APT_LOCK_TIMEOUT}s, proceeding anyway."
      return 0
    fi
    if [ "$waited" -eq 0 ]; then
      echo "==> Another apt/dpkg process is running, waiting for it to finish..."
    fi
    sleep 5
    waited=$((waited + 5))
  done
}

apt_repair() {
  dpkg --configure -a || true
  apt-get install -f -y -qq || true
}

# Retry an apt command, repairing dpkg state between attempts. Transient
# mirror/DNS failures and lock races both resolve on a second try.
apt_retry() {
  local attempt=1
  while true; do
    apt_wait_for_lock
    if "$@"; then
      return 0
    fi
    if [ "$attempt" -ge 3 ]; then
      echo "ERROR: '$*' failed after 3 attempts."
      return 1
    fi
    echo "==> '$*' failed (attempt $attempt/3), repairing dpkg and retrying..."
    apt_repair
    attempt=$((attempt + 1))
    sleep 5
  done
}

fetch_url() {
  local url="$1" dest="$2" attempt=1
  while true; do
    if curl -fsSL --max-time 300 -o "$dest" "$url"; then
      return 0
    fi
    if [ "$attempt" -ge 3 ]; then
      echo "ERROR: could not download $url after 3 attempts." >&2
      return 1
    fi
    echo "==> Download failed (attempt $attempt/3), retrying: $url"
    attempt=$((attempt + 1))
    sleep 5
  done
}

# Assemble the payload from the server, reusing local files whose hash already
# matches. FTPA.wav alone is ~48 MB and never changes, so a code-only update
# transfers tens of KB rather than the whole payload — the difference between
# seconds and many minutes on a Pi Zero W's wifi.
fetch_source() {
  local out="$1"
  local manifest="$WORK_DIR/manifest.json"

  mkdir -p "$out"
  echo "==> Fetching payload manifest..."
  fetch_url "$BASE_URL/pi/manifest.json" "$manifest" || return 1

  SOURCE_HASH="$(python3 -c '
import json, sys
with open(sys.argv[1]) as f:
    print(json.load(f).get("hash", ""))
' "$manifest")"

  if [ -z "$SOURCE_HASH" ]; then
    echo "ERROR: manifest has no hash — is $BASE_URL serving the app?" >&2
    return 1
  fi
  echo "==> Payload version: $SOURCE_HASH"

  # name<TAB>sha256 for every file in the payload
  local list="$WORK_DIR/manifest.tsv"
  python3 -c '
import json, sys
with open(sys.argv[1]) as f:
    files = json.load(f).get("files", {})
for name in sorted(files):
    # A name with a separator would let a compromised/typo manifest write
    # outside the payload dir; dotfiles are not part of the flat payload.
    if "/" in name or "\\" in name or name.startswith("."):
        continue
    sha = files[name].get("sha256", "")
    if sha:
        sys.stdout.write(name + "\t" + sha + "\n")
' "$manifest" | tr -d '\r' > "$list"

  if [ ! -s "$list" ]; then
    echo "ERROR: manifest listed no files." >&2
    return 1
  fi

  local name want have reused=0 fetched=0
  while IFS=$'\t' read -r name want; do
    [ -n "$name" ] || continue
    have=""
    if [ -f "$DIR/$name" ]; then
      have="$(sha256sum "$DIR/$name" 2>/dev/null | awk '{print $1}')"
    fi

    if [ "$have" = "$want" ]; then
      cp -f "$DIR/$name" "$out/$name"
      reused=$((reused + 1))
      continue
    fi

    fetch_url "$BASE_URL/pi/$name" "$out/$name" || return 1

    # Verify what we got. A truncated download that still builds is exactly the
    # kind of corruption that would otherwise ship silently to a transmitter.
    have="$(sha256sum "$out/$name" | awk '{print $1}')"
    if [ "$have" != "$want" ]; then
      echo "ERROR: checksum mismatch for $name (expected $want, got $have)" >&2
      return 1
    fi
    fetched=$((fetched + 1))
  done < "$list"

  cut -f1 "$list" > "$WORK_DIR/source-files.txt"
  echo "==> Payload ready: $fetched downloaded, $reused unchanged"
  return 0
}

echo "==> FM Playlist setup for user: $REAL_USER  dir: $DIR"
echo "==> Source: $BASE_URL"

if grep -qi 'Raspberry Pi 5' /proc/device-tree/model 2>/dev/null; then
  echo "WARNING: Pi 5 (BCM2712) cannot drive pi_fm_rds - the DMA/PWM subsystem changed."
  echo "         Setup will continue, but the transmitter will not broadcast."
fi

echo "==> Preparing package manager..."
apt_wait_for_lock
apt_repair
apt_retry apt-get update -qq
apt_retry apt-get install -y -qq "${APT_PACKAGES[@]}"

for required_bin in curl ffmpeg make gcc python3 espeak sha256sum; do
  if ! command -v "$required_bin" >/dev/null 2>&1; then
    echo "ERROR: required command '$required_bin' is missing after package install."
    exit 1
  fi
done
if ! python3 -c 'import requests' >/dev/null 2>&1; then
  echo "ERROR: python3 'requests' module is missing after package install."
  exit 1
fi

mkdir -p "$DIR" "$STATE_DIR" "$DIR/commercials" "$DIR/sound-bytes"

echo "==> Fetching source payload..."
SRC="$WORK_DIR/src"
fetch_source "$SRC"

for required in pi_daemon.py Makefile pi_fm_rds.c run.sh wifi_apply.sh FTPA.wav; do
  if [ ! -f "$SRC/$required" ]; then
    echo "ERROR: payload is missing $required"
    exit 1
  fi
done

if [ -f "$DIR/pi_daemon.py" ] || [ -f "$DIR/pi_fm_rds" ]; then
  echo "==> Existing install - refreshing all app-controlled source files..."
else
  echo "==> First install - installing all source files..."
fi

NEW_NATIVE_SHA="$(native_checksum "$SRC")"
OLD_NATIVE_SHA="$(cat "$NATIVE_SHA_FILE" 2>/dev/null || true)"
NEW_SOURCE_MANIFEST="$WORK_DIR/source-files.txt"

# ── Build in staging, install only on success ────────────────────────────────
# The old flow ran `make clean && make app` directly in the live tree, so a
# failed build (OOM on a single-core Zero, disk full) deleted the working
# transmitter binary. Nothing looked wrong until the next restart, at which
# point the station went silent with only SSH to recover it. Build somewhere
# disposable instead, and touch the live tree only once a binary exists.
NEED_BUILD=""
if [ ! -x "$DIR/pi_fm_rds" ]; then
  NEED_BUILD="binary missing"
elif [ "$FMPLAYLIST_FORCE_REBUILD" = "1" ]; then
  NEED_BUILD="forced"
elif [ "$NEW_NATIVE_SHA" != "$OLD_NATIVE_SHA" ]; then
  NEED_BUILD="native source changed"
fi

if [ -n "$NEED_BUILD" ]; then
  echo "==> Building pi_fm_rds in staging ($NEED_BUILD)..."
  echo "    (single-core Pi Zero: this can take several minutes with no output)"
  if ! ( cd "$SRC" && make app ); then
    echo "ERROR: build failed. The existing install is untouched and still running."
    exit 1
  fi
  if [ ! -x "$SRC/pi_fm_rds" ]; then
    echo "ERROR: build reported success but no pi_fm_rds was produced."
    echo "       The existing install is untouched and still running."
    exit 1
  fi
  echo "==> Build OK"
else
  echo "==> Native source unchanged — reusing existing binary."
  cp -f "$DIR/pi_fm_rds" "$SRC/pi_fm_rds"
fi

# ── Snapshot the current install so postinstall can roll back ────────────────
# Only code + binary. Songs, commercials and sound bytes also live in $DIR and
# can run to gigabytes; they are never part of an update, so never copied.
echo "==> Snapshotting current install..."
rm -rf "$LAST_GOOD_DIR.partial"
mkdir -p "$LAST_GOOD_DIR.partial"
if [ -f "$SOURCE_MANIFEST_FILE" ]; then
  while IFS= read -r old_file; do
    case "$old_file" in ""|*/*|*\\*) continue ;; esac
    [ -f "$DIR/$old_file" ] && cp -f "$DIR/$old_file" "$LAST_GOOD_DIR.partial/" || true
  done < "$SOURCE_MANIFEST_FILE"
fi
[ -f "$DIR/pi_fm_rds" ] && cp -f "$DIR/pi_fm_rds" "$LAST_GOOD_DIR.partial/" || true
[ -f "$SOURCE_MANIFEST_FILE" ] && cp -f "$SOURCE_MANIFEST_FILE" "$LAST_GOOD_DIR.partial/source-files.txt" || true
[ -f "$NATIVE_SHA_FILE" ] && cp -f "$NATIVE_SHA_FILE" "$LAST_GOOD_DIR.partial/native.sha256" || true
rm -rf "$LAST_GOOD_DIR"
mv "$LAST_GOOD_DIR.partial" "$LAST_GOOD_DIR"

# ── Install ──────────────────────────────────────────────────────────────────
if [ -f "$SOURCE_MANIFEST_FILE" ]; then
  while IFS= read -r old_file; do
    case "$old_file" in ""|*/*|*\\*) continue ;; esac
    if ! grep -Fxq "$old_file" "$NEW_SOURCE_MANIFEST"; then
      rm -f "$DIR/$old_file"
      echo "==> Removed stale source file: $old_file"
    fi
  done < "$SOURCE_MANIFEST_FILE"
fi

# config.json is intentionally not in the payload so local-only values survive
# until the deterministic rewrite below.
find "$SRC" -maxdepth 1 -type f -exec cp -f {} "$DIR/" \;
cp "$NEW_SOURCE_MANIFEST" "$SOURCE_MANIFEST_FILE"
echo "$NEW_NATIVE_SHA" > "$NATIVE_SHA_FILE"
chmod +x "$DIR/pi_fm_rds" 2>/dev/null || true
chmod +x "$DIR/run.sh" "$DIR/wifi_apply.sh" "$DIR/whisper.sh" 2>/dev/null || true

# Saved wifi networks live outside $DIR so the stale-source sweep above can't
# delete them. Root-only: the file holds PSKs in the clear.
mkdir -p /etc/fmplaylist
chmod 700 /etc/fmplaylist

echo "==> Writing config.json..."
python3 - "$DIR/config.json" "$BASE_URL" "$TOKEN" "$DIR" <<'PY'
import json
import os
import sys
import tempfile

path, base_url, token, install_dir = sys.argv[1:5]

old = {}
try:
    with open(path) as f:
        old = json.load(f)
except Exception:
    old = {}

def keep(key, default):
    return old.get(key, default)

cfg = {
    'server_url': base_url,
    'api_key': token,
    # Preserved, not reset: the daemon persists admin-set frequency changes here
    # (send_heartbeat -> save_local_config). Hardcoding 96.9 silently knocked
    # every updated Pi back to the default frequency.
    'freq': keep('freq', 96.9),
    'pi_code': keep('pi_code', 'C0DE'),
    'callsign': keep('callsign', '96.9 FM '),
    'song_dir': install_dir,
    'commercial_dir': os.path.join(install_dir, 'commercials'),
    'sound_byte_dir': os.path.join(install_dir, 'sound-bytes'),
    'fallback_song': 'FTPA.wav',
    'local_station_id_path': os.path.join(install_dir, 'station_id.wav'),
    'local_station_id_hash': keep('local_station_id_hash', ''),
    'poll_interval_seconds': keep('poll_interval_seconds', 5),
    'verify_ssl': keep('verify_ssl', False),
}

fd, tmp = tempfile.mkstemp(prefix='config.', suffix='.json', dir=os.path.dirname(path))
try:
    with os.fdopen(fd, 'w') as f:
        json.dump(cfg, f, indent=2)
        f.write('\n')
    os.replace(tmp, path)
finally:
    if os.path.exists(tmp):
        os.unlink(tmp)
PY

chown -R "$REAL_USER:$REAL_USER" "$PI_DIR"

echo "==> Installing systemd service..."
cat > "$SVC" << SERVICE
[Unit]
Description=FM Playlist Daemon
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=/usr/bin/python3 -u $DIR/pi_daemon.py
WorkingDirectory=$DIR
Restart=always
RestartSec=10
User=root

[Install]
WantedBy=multi-user.target
SERVICE

systemctl daemon-reload
systemctl enable fmplaylist >/dev/null 2>&1

# The restart must not be a child of the unit being restarted. During a
# self-update this script runs inside fmplaylist.service's cgroup, so a plain
# `systemctl restart` had systemd SIGTERM the installer mid-flight — which is
# why the verification below never ran on any update, only on manual installs.
# Hand both the restart and the health check to PID 1 and exit cleanly.
echo "==> Scheduling detached restart + health check..."
record_update "pending" "restart scheduled"

# Persist a copy to re-exec: when this ran via `curl | bash` there is no script
# file on disk to point systemd-run at.
if [ ! -f "$SCRIPT_SELF" ] || [ "$SCRIPT_SELF" = "/usr/local/lib/fmplaylist-setup.sh" ]; then
  mkdir -p /usr/local/lib
  if fetch_url "$BASE_URL/pi/setup.sh" /usr/local/lib/fmplaylist-setup.sh; then
    chmod 700 /usr/local/lib/fmplaylist-setup.sh
    SCRIPT_SELF="/usr/local/lib/fmplaylist-setup.sh"
  fi
else
  cp -f "$SCRIPT_SELF" /usr/local/lib/fmplaylist-setup.sh 2>/dev/null || true
  chmod 700 /usr/local/lib/fmplaylist-setup.sh 2>/dev/null || true
fi

if [ -f "$SCRIPT_SELF" ] && command -v systemd-run >/dev/null 2>&1; then
  systemctl reset-failed fmplaylist-postinstall >/dev/null 2>&1 || true
  systemd-run --unit=fmplaylist-postinstall --collect \
    /bin/bash "$SCRIPT_SELF" --postinstall "$SOURCE_HASH" >/dev/null
  echo ""
  echo "Done! Source installed. Daemon restarting under systemd."
  echo "  Health check will roll back automatically if it fails to come up."
  echo "  Status: systemctl status fmplaylist --no-pager"
  echo "  Logs:   sudo journalctl -u fmplaylist -f"
  exit 0
fi

# systemd-run missing: fall back to the in-line path. Safe here because without
# systemd-run this is almost certainly a manual install, not a self-update.
echo "==> systemd-run unavailable — restarting inline"
if ! restart_and_verify; then
  if rollback_install && restart_and_verify; then
    record_update "rolled_back" "daemon failed health check; previous version restored"
  else
    record_update "failed" "update failed and rollback did not recover"
  fi
  exit 1
fi
record_update "ok" "installed $SOURCE_HASH"

echo ""
echo "Done! Source refreshed, config written, daemon running."
echo "  Status: systemctl status fmplaylist --no-pager"
echo "  Logs:   sudo journalctl -u fmplaylist -f"
