<?php

namespace App\Http\Controllers;

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
        'FTPA.wav',
    ];

    public function file(string $filename): BinaryFileResponse
    {
        abort_if(! in_array($filename, self::ALLOWED, true), 404);

        $path = base_path("PiFmRds/src/{$filename}");

        abort_if(! file_exists($path), 404);

        return response()->download($path, $filename);
    }

    public function setup(): Response
    {
        $base = rtrim((string) config('app.url'), '/');

        $script = <<<BASH
#!/usr/bin/env bash
# FM Playlist Pi Setup / Update
# Usage: curl -fsSL {$base}/pi/setup.sh | sudo bash -s -- YOUR_TOKEN
set -e

TOKEN="\${1:-}"
if [ -z "\$TOKEN" ]; then
  echo "ERROR: pass your Pi token as an argument."
  echo "  curl -fsSL {$base}/pi/setup.sh | sudo bash -s -- YOUR_TOKEN"
  exit 1
fi

# Detect the real user who invoked sudo
REAL_USER="\${SUDO_USER:-\$(logname 2>/dev/null || echo pi)}"
HOME_DIR="/home/\$REAL_USER"
DIR="\$HOME_DIR/PiFmRds/src"
SVC="/etc/systemd/system/fmplaylist.service"

echo "==> FM Playlist setup for user: \$REAL_USER  dir: \$DIR"

# ── 1. Dependencies ───────────────────────────────────────────────────────────
apt-get update -qq
apt-get install -y -qq git ffmpeg build-essential python3 python3-requests libsndfile1-dev

# ── 2. Clone or update source files ──────────────────────────────────────────
mkdir -p "\$DIR"

if [ ! -d "\$HOME_DIR/PiFmRds/.git" ]; then
  echo "==> First install — cloning source files..."
  git clone --depth 1 https://github.com/austiz/fmplaylist.git /tmp/fmplaylist-setup
  cp -r /tmp/fmplaylist-setup/PiFmRds/src/. "\$DIR/"
  rm -rf /tmp/fmplaylist-setup
else
  echo "==> Updating daemon to latest..."
  curl -fsSL "{$base}/pi/pi_daemon.py" -o "\$DIR/pi_daemon.py"
fi
chown -R "\$REAL_USER:\$REAL_USER" "\$HOME_DIR/PiFmRds"

# ── 3. Write / update config.json ────────────────────────────────────────────
echo "==> Writing config.json..."
cat > "\$DIR/config.json" << CONF
{
  "server_url": "{$base}",
  "api_key": "\$TOKEN",
  "freq": 96.9,
  "pi_code": "C0DE",
  "callsign": "96.9 FM ",
  "song_dir": "\$DIR",
  "fallback_song": "FTPA.wav",
  "poll_interval_seconds": 5,
  "verify_ssl": false
}
CONF
chown "\$REAL_USER:\$REAL_USER" "\$DIR/config.json"

# ── 4. Compile (only if binary missing) ──────────────────────────────────────
if [ ! -f "\$DIR/pi_fm_rds" ]; then
  echo "==> Compiling pi_fm_rds..."
  cd "\$DIR" && make
else
  echo "==> pi_fm_rds already compiled, skipping."
fi

# ── 5. Systemd service ────────────────────────────────────────────────────────
echo "==> Installing systemd service..."
cat > "\$SVC" << SERVICE
[Unit]
Description=FM Playlist Daemon
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=/usr/bin/python3 -u \$DIR/pi_daemon.py
WorkingDirectory=\$DIR
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
echo "✓ Done! Daemon restarted."
echo "  Logs: sudo journalctl -u fmplaylist -f"
BASH;

        return response($script, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
