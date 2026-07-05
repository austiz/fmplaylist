<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PiSetupController extends Controller
{
    private const ALLOWED = [
        'pi_daemon.py',
        'pi_fm_rds.c',
        'fm_mpx.c',
        'fm_mpx.h',
        'rds.c',
        'rds.h',
        'rds_strings.c',
        'rds_strings.h',
        'rds_wav.c',
        'mailbox.c',
        'mailbox.h',
        'control_pipe.c',
        'control_pipe.h',
        'waveforms.c',
        'waveforms.h',
        'Makefile',
        'run.sh',
        'wifi_setup.sh',
        'FTPA.wav',
    ];

    public function file(string $filename): BinaryFileResponse
    {
        abort_if(! in_array($filename, self::ALLOWED, true), 404);

        $path = base_path("PiFmRds/src/{$filename}");

        abort_if(! file_exists($path), 404);

        return response()->download($path, $filename);
    }

    public function setup(Request $request): Response
    {
        $script = str_replace('__BASE_URL__', $this->setupBaseUrl($request), <<<'BASH'
#!/usr/bin/env bash
# FM Playlist Pi Setup / Update
# Usage: curl -fsSL __BASE_URL__/pi/setup.sh | sudo bash -s -- YOUR_TOKEN
set -Eeuo pipefail

BASE_URL="__BASE_URL__"
FMPLAYLIST_REPO="${FMPLAYLIST_REPO:-https://github.com/austiz/fmplaylist.git}"
FMPLAYLIST_REF="${FMPLAYLIST_REF:-main}"
FMPLAYLIST_FORCE_REBUILD="${FMPLAYLIST_FORCE_REBUILD:-0}"

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

REAL_USER="${SUDO_USER:-$(logname 2>/dev/null || echo pi)}"
HOME_DIR="/home/$REAL_USER"
PI_DIR="$HOME_DIR/PiFmRds"
DIR="$PI_DIR/src"
STATE_DIR="$PI_DIR/.install-state"
NATIVE_SHA_FILE="$STATE_DIR/native.sha256"
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

echo "==> FM Playlist setup for user: $REAL_USER  dir: $DIR"
echo "==> Source: $FMPLAYLIST_REPO#$FMPLAYLIST_REF"

apt-get update -qq
apt-get install -y -qq git ffmpeg build-essential python3 python3-requests libsndfile1-dev espeak

echo "==> Fetching fresh source snapshot..."
git clone --depth 1 --branch "$FMPLAYLIST_REF" "$FMPLAYLIST_REPO" "$WORK_DIR/repo"
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

# Copy tracked top-level Pi source/support files. config.json is intentionally
# skipped so local-only values can be preserved before the deterministic rewrite.
find "$SRC" -maxdepth 1 -type f ! -name 'config.json' -exec cp -f {} "$DIR/" \;
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
  cd "$DIR" && make clean && make app
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
systemctl enable fmplaylist
systemctl restart fmplaylist

echo ""
echo "Done! Source refreshed, config written, daemon restarted."
echo "  Logs: sudo journalctl -u fmplaylist -f"
BASH);

        return response($script, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    private function setupBaseUrl(Request $request): string
    {
        $configured = rtrim((string) config('app.url'), '/');

        if (str_starts_with($configured, 'http://') || str_starts_with($configured, 'https://')) {
            return $configured;
        }

        $host = $request->getHttpHost();

        if ($host !== '') {
            $isLocal = str_starts_with($host, 'localhost')
                || str_starts_with($host, '127.0.0.1')
                || str_starts_with($host, '[::1]');

            return ($isLocal ? $request->getScheme() : 'https')."://{$host}";
        }

        return 'https://fmplaylist.com';
    }
}
