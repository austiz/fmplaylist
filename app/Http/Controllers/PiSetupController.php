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
        $base = rtrim(config('app.url'), '/');
        $dir  = '/home/pi/PiFmRds/src';
        $svc  = '/etc/systemd/system/fmplaylist.service';

        $files = implode(' ', self::ALLOWED);

        $config = json_encode([
            'server_url'            => $base,
            'api_key'               => 'PASTE_YOUR_TOKEN_HERE',
            'freq'                  => 96.9,
            'pi_code'               => 'C0DE',
            'callsign'              => '96.9 FM ',
            'song_dir'              => $dir,
            'fallback_song'         => 'FTPA.wav',
            'poll_interval_seconds' => 30,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $service = implode("\n", [
            '[Unit]',
            'Description=FM Playlist Pi Daemon',
            'After=network-online.target',
            'Wants=network-online.target',
            '',
            '[Service]',
            "WorkingDirectory={$dir}",
            "ExecStart=/usr/bin/python3 {$dir}/pi_daemon.py",
            'Restart=always',
            'RestartSec=10',
            'User=root',
            '',
            '[Install]',
            'WantedBy=multi-user.target',
        ]);

        $script = <<<BASH
#!/bin/bash
# FM Playlist Pi Setup
# Usage (with token): curl -fsSL {$base}/pi/setup.sh | sudo bash -s -- YOUR_TOKEN
# Usage (manual):     curl -fsSL {$base}/pi/setup.sh | sudo bash
set -e

TOKEN="\${1:-}"
DIR="{$dir}"

echo "=== FM Playlist Pi Setup ==="

apt-get update -q
apt-get install -y -q python3 ffmpeg build-essential libsndfile1-dev

mkdir -p "\$DIR"
cd "\$DIR"

echo "Downloading source files..."
for f in {$files}; do
    printf "  %s\\n" "\$f"
    wget -q -O "\$f" "{$base}/pi/\$f"
done

chmod +x run.sh

echo "Building pi_fm_rds..."
make

if [ ! -f config.json ]; then
    cat > config.json << 'CONFIG'
{$config}
CONFIG
fi

if [ -n "\$TOKEN" ]; then
    python3 -c "
import json, sys
with open('config.json') as f: c = json.load(f)
c['api_key'] = sys.argv[1]
with open('config.json', 'w') as f: json.dump(c, f, indent=2)
print('api_key written to config.json')
" "\$TOKEN"
fi

cat > {$svc} << 'SERVICE'
{$service}
SERVICE

systemctl daemon-reload
systemctl enable fmplaylist

echo ""
echo "=== Setup complete ==="
echo ""
if [ -z "\$TOKEN" ]; then
    echo "Next: edit \$DIR/config.json and set api_key"
    echo "  Get your token from {$base}/admin/tokens"
    echo ""
fi
echo "Start the daemon:"
echo "  sudo systemctl start fmplaylist"
echo "  sudo systemctl status fmplaylist"
BASH;

        return response($script, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
