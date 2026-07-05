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
        $base = $this->setupBaseUrl($request);

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

REAL_USER="\${SUDO_USER:-\$(logname 2>/dev/null || echo pi)}"
HOME_DIR="/home/\$REAL_USER"
PI_DIR="\$HOME_DIR/PiFmRds"
DIR="\$PI_DIR/src"
SVC="/etc/systemd/system/fmplaylist.service"

echo "==> FM Playlist setup for user: \$REAL_USER  dir: \$DIR"

apt-get update -qq
apt-get install -y -qq git ffmpeg build-essential python3 python3-requests libsndfile1-dev espeak

mkdir -p "\$DIR"

if [ ! -f "\$DIR/pi_daemon.py" ] && [ ! -f "\$DIR/pi_fm_rds" ]; then
  echo "==> First install - cloning source files..."
  rm -rf /tmp/fmplaylist-setup
  git clone --depth 1 https://github.com/austiz/fmplaylist.git /tmp/fmplaylist-setup
  cp -r /tmp/fmplaylist-setup/PiFmRds/src/. "\$DIR/"
  rm -rf /tmp/fmplaylist-setup
else
  echo "==> Existing install - refreshing daemon and helper files..."
  for file in pi_daemon.py run.sh wifi_setup.sh; do
    curl -fsSL "{$base}/pi/\$file" -o "\$DIR/\$file"
  done
fi

chown -R "\$REAL_USER:\$REAL_USER" "\$PI_DIR"

echo "==> Writing config.json..."
cat > "\$DIR/config.json" << CONF
{
  "server_url": "{$base}",
  "api_key": "\$TOKEN",
  "freq": 96.9,
  "pi_code": "C0DE",
  "callsign": "96.9 FM ",
  "song_dir": "\$DIR",
  "commercial_dir": "\$DIR/commercials",
  "sound_byte_dir": "\$DIR/sound-bytes",
  "fallback_song": "FTPA.wav",
  "local_station_id_path": "\$DIR/station_id.wav",
  "local_station_id_hash": "",
  "poll_interval_seconds": 5,
  "verify_ssl": false
}
CONF
chown "\$REAL_USER:\$REAL_USER" "\$DIR/config.json"

if [ ! -f "\$DIR/pi_fm_rds" ]; then
  echo "==> Compiling pi_fm_rds..."
  cd "\$DIR" && make
else
  echo "==> pi_fm_rds already compiled, skipping."
fi

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
echo "Done! Daemon restarted."
echo "  Logs: sudo journalctl -u fmplaylist -f"
BASH;

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
