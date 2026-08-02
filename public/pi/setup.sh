#!/usr/bin/env bash
# FM Playlist Pi Setup / Update
# Usage: curl -fsSL https://fmplaylist.com/pi/setup.sh | sudo bash -s -- YOUR_TOKEN
set -Eeuo pipefail

BASE_URL="https://fmplaylist.com"
FMPLAYLIST_REPO="${FMPLAYLIST_REPO:-https://github.com/austiz/fmplaylist.git}"
FMPLAYLIST_REF="${FMPLAYLIST_REF:-main}"
FMPLAYLIST_FORCE_REBUILD="${FMPLAYLIST_FORCE_REBUILD:-0}"
APT_LOCK_TIMEOUT="${APT_LOCK_TIMEOUT:-600}"
APT_PACKAGES=(git ffmpeg build-essential python3 python3-requests libsndfile1-dev espeak)

# Never let a package's post-install prompt block an unattended run.
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a

TOKEN="${1:-}"
if [ -z "$TOKEN" ]; then
  echo "ERROR: pass your Pi token as an argument."
  echo "  curl -fsSL $BASE_URL/pi/setup.sh | sudo bash -s -- YOUR_TOKEN"
  exit 1
fi

if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR: run through sudo so system packages and systemd can be installed."
  exit 1
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

# Clone into a guaranteed-empty target: a clone killed partway leaves a
# non-empty directory that makes every later attempt fail on its own debris.
clone_source() {
  local attempt=1
  while true; do
    rm -rf "$WORK_DIR/repo"
    if git clone --depth 1 --branch "$FMPLAYLIST_REF" "$FMPLAYLIST_REPO" "$WORK_DIR/repo"; then
      return 0
    fi
    if [ "$attempt" -ge 3 ]; then
      echo "ERROR: could not clone $FMPLAYLIST_REPO#$FMPLAYLIST_REF after 3 attempts."
      echo "       Check network/DNS: ping -c3 github.com"
      rm -rf "$WORK_DIR/repo"
      return 1
    fi
    echo "==> Clone failed (attempt $attempt/3), retrying..."
    attempt=$((attempt + 1))
    sleep 5
  done
}

echo "==> FM Playlist setup for user: $REAL_USER  dir: $DIR"
echo "==> Source: $FMPLAYLIST_REPO#$FMPLAYLIST_REF"

if grep -qi 'Raspberry Pi 5' /proc/device-tree/model 2>/dev/null; then
  echo "WARNING: Pi 5 (BCM2712) cannot drive pi_fm_rds - the DMA/PWM subsystem changed."
  echo "         Setup will continue, but the transmitter will not broadcast."
fi

echo "==> Preparing package manager..."
apt_wait_for_lock
apt_repair
apt_retry apt-get update -qq
apt_retry apt-get install -y -qq "${APT_PACKAGES[@]}"

for required_bin in git ffmpeg make gcc python3 espeak; do
  if ! command -v "$required_bin" >/dev/null 2>&1; then
    echo "ERROR: required command '$required_bin' is missing after package install."
    exit 1
  fi
done
if ! python3 -c 'import requests' >/dev/null 2>&1; then
  echo "ERROR: python3 'requests' module is missing after package install."
  exit 1
fi

echo "==> Fetching fresh source snapshot..."
clone_source
SRC="$WORK_DIR/repo/PiFmRds/src"

for required in pi_daemon.py Makefile pi_fm_rds.c run.sh wifi_setup.sh FTPA.wav; do
  if [ ! -f "$SRC/$required" ]; then
    echo "ERROR: source snapshot missing PiFmRds/src/$required"
    exit 1
  fi
done

mkdir -p "$DIR" "$STATE_DIR" "$DIR/commercials" "$DIR/sound-bytes"

if [ -f "$DIR/pi_daemon.py" ] || [ -f "$DIR/pi_fm_rds" ]; then
  echo "==> Existing install - refreshing all app-controlled source files..."
else
  echo "==> First install - installing all source files..."
fi

NEW_NATIVE_SHA="$(native_checksum "$SRC")"
OLD_NATIVE_SHA="$(cat "$NATIVE_SHA_FILE" 2>/dev/null || true)"
NEW_SOURCE_MANIFEST="$WORK_DIR/source-files.txt"
find "$SRC" -maxdepth 1 -type f ! -name 'config.json' -printf '%f\n' | sort > "$NEW_SOURCE_MANIFEST"

if [ -f "$SOURCE_MANIFEST_FILE" ]; then
  while IFS= read -r old_file; do
    case "$old_file" in
      ""|*/*|*\\*) continue ;;
    esac
    if ! grep -Fxq "$old_file" "$NEW_SOURCE_MANIFEST"; then
      rm -f "$DIR/$old_file"
      echo "==> Removed stale source file: $old_file"
    fi
  done < "$SOURCE_MANIFEST_FILE"
fi

# Copy tracked top-level Pi source/support files. config.json is intentionally
# skipped so local-only values can be preserved before the deterministic rewrite.
find "$SRC" -maxdepth 1 -type f ! -name 'config.json' -exec cp -f {} "$DIR/" \;
cp "$NEW_SOURCE_MANIFEST" "$SOURCE_MANIFEST_FILE"
chmod +x "$DIR/run.sh" "$DIR/wifi_setup.sh" "$DIR/whisper.sh" 2>/dev/null || true

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
    'freq': 96.9,
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

REBUILD_REASON=""
if [ ! -x "$DIR/pi_fm_rds" ]; then
  REBUILD_REASON="binary missing"
elif [ "$FMPLAYLIST_FORCE_REBUILD" = "1" ]; then
  REBUILD_REASON="forced"
elif [ "$NEW_NATIVE_SHA" != "$OLD_NATIVE_SHA" ]; then
  REBUILD_REASON="native source changed"
fi

if [ -n "$REBUILD_REASON" ]; then
  echo "==> Rebuilding pi_fm_rds ($REBUILD_REASON)..."
  echo "    (single-core Pi Zero: this can take several minutes with no output)"
  cd "$DIR" && make clean && make app
  if [ ! -x "$DIR/pi_fm_rds" ]; then
    echo "ERROR: build reported success but $DIR/pi_fm_rds is missing."
    exit 1
  fi
  echo "$NEW_NATIVE_SHA" > "$NATIVE_SHA_FILE"
else
  echo "==> pi_fm_rds native source unchanged, skipping rebuild."
fi

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
systemctl restart fmplaylist

# Don't claim success on a service that dies on startup (bad token, missing
# audio file, unreadable config). Give it a few seconds to fail, then check.
echo "==> Verifying daemon..."
STARTUP_OK=0
for _ in 1 2 3 4 5 6 7 8 9 10; do
  if systemctl is-active --quiet fmplaylist; then
    STARTUP_OK=1
    break
  fi
  sleep 1
done

if [ "$STARTUP_OK" -ne 1 ]; then
  echo ""
  echo "ERROR: fmplaylist service is not running. Last 30 log lines:"
  journalctl -u fmplaylist -n 30 --no-pager || true
  exit 1
fi

echo ""
echo "Done! Source refreshed, config written, daemon running."
echo "  Status: systemctl status fmplaylist --no-pager"
echo "  Logs:   sudo journalctl -u fmplaylist -f"
