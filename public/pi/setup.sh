#!/usr/bin/env bash
# FM Playlist Pi Setup
# Usage: curl -fsSL https://fmplaylist.com/pi/setup.sh | sudo bash -s -- YOUR_TOKEN
set -e

TOKEN="${1:-}"
if [ -z "$TOKEN" ]; then
  echo "ERROR: Pass your Pi token as an argument."
  echo "  curl -fsSL https://fmplaylist.com/pi/setup.sh | sudo bash -s -- YOUR_TOKEN"
  exit 1
fi

# Detect the non-root user who invoked sudo
REAL_USER="${SUDO_USER:-$(logname 2>/dev/null || echo pi)}"
HOME_DIR="/home/$REAL_USER"
PI_DIR="$HOME_DIR/PiFmRds"

echo "==> Setting up FM Playlist on Pi as user: $REAL_USER"

# ── 1. Dependencies ─────────────────────────────────────────────────────────
echo "==> Installing dependencies..."
apt-get update -qq
apt-get install -y -qq git ffmpeg build-essential python3 python3-requests

# ── 2. Download PiFmRds ──────────────────────────────────────────────────────
if [ ! -d "$PI_DIR" ]; then
  echo "==> Downloading FM Playlist files..."
  git clone https://github.com/austiz/fmplaylist.git /tmp/fmplaylist-setup
  cp -r /tmp/fmplaylist-setup/PiFmRds "$PI_DIR"
  rm -rf /tmp/fmplaylist-setup
  chown -R "$REAL_USER:$REAL_USER" "$PI_DIR"
else
  echo "==> PiFmRds already present, skipping clone."
fi

# ── 3. Write config.json ─────────────────────────────────────────────────────
echo "==> Writing config.json..."
cat > "$PI_DIR/src/config.json" << CONF
{
  "server_url": "https://fmplaylist.com",
  "api_key": "$TOKEN",
  "freq": 96.9,
  "pi_code": "C0DE",
  "callsign": "96.9 FM ",
  "song_dir": "$PI_DIR/src",
  "fallback_song": "FTPA.wav",
  "local_station_id_path": "$PI_DIR/src/station_id.wav",
  "local_station_id_hash": "",
  "poll_interval_seconds": 30
}
CONF
chown "$REAL_USER:$REAL_USER" "$PI_DIR/src/config.json"

# ── 4. Compile pi_fm_rds ─────────────────────────────────────────────────────
if [ ! -f "$PI_DIR/src/pi_fm_rds" ]; then
  echo "==> Compiling pi_fm_rds..."
  cd "$PI_DIR/src" && make
else
  echo "==> pi_fm_rds already compiled, skipping."
fi

# ── 5. Systemd service ───────────────────────────────────────────────────────
echo "==> Installing systemd service..."
cat > /etc/systemd/system/fmplaylist.service << SERVICE
[Unit]
Description=FM Playlist Daemon
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=/usr/bin/python3 $PI_DIR/src/pi_daemon.py
WorkingDirectory=$PI_DIR/src
Restart=always
RestartSec=10
User=root

[Install]
WantedBy=multi-user.target
SERVICE

systemctl daemon-reload
systemctl enable fmplaylist
systemctl start fmplaylist

echo ""
echo "✓ Setup complete! FM Playlist daemon is running."
echo "  Check status:  sudo systemctl status fmplaylist"
echo "  Live logs:     sudo journalctl -u fmplaylist -f"
echo "  Frequency:     96.9 FM"
