#!/usr/bin/env bash
#
# wifi_setup.sh — Apply wifi.conf to the Pi's WiFi configuration
#
# Reads wifi.conf from the boot partition (editable from Windows) and connects
# the Pi to the configured network. Supports both:
#   - Raspberry Pi OS Bullseye and earlier (wpa_supplicant)
#   - Raspberry Pi OS Bookworm and later (NetworkManager / nmcli)
#
# Usage:
#   sudo bash wifi_setup.sh
#
# To run automatically on boot, add to /etc/rc.local before "exit 0":
#   bash /home/pi/PiFmRds/src/wifi_setup.sh

set -euo pipefail

# ── Locate wifi.conf ───────────────────────────────────────────────────────────
# Check the FAT boot partition first (visible from Windows), then fall back to
# a copy next to this script.
CONF=""
for candidate in \
    /boot/firmware/wifi.conf \
    /boot/wifi.conf \
    "$(dirname "$(readlink -f "$0")")/../wifi.conf"; do
  if [[ -f "$candidate" ]]; then
    CONF="$candidate"
    break
  fi
done

if [[ -z "$CONF" ]]; then
  echo "ERROR: wifi.conf not found."
  echo "  Copy PiFmRds/wifi.conf to the SD card boot partition and edit it."
  exit 1
fi

echo "[wifi] reading config from $CONF"

# Source the config — expects SSID, PASSWORD, COUNTRY
SSID=""
PASSWORD=""
COUNTRY="US"

eval "$(python3 - "$CONF" <<'PY'
import pathlib
import shlex
import sys

path = pathlib.Path(sys.argv[1])
values = {}

for raw in path.read_text(encoding="utf-8").splitlines():
    line = raw.strip()
    if not line or line.startswith("#") or "=" not in line:
        continue

    key, value = line.split("=", 1)
    key = key.strip()
    value = value.strip()

    if len(value) >= 2 and value[0] == value[-1] and value[0] in {'"', "'"}:
        value = value[1:-1]

    values[key] = value

for key in ("SSID", "PASSWORD", "COUNTRY"):
    print(f"{key}={shlex.quote(values.get(key, ''))}")
PY
)"
# shellcheck disable=SC1090
source "$CONF"

if [[ -z "$SSID" || -z "$PASSWORD" ]]; then
  echo "ERROR: SSID and PASSWORD must both be set in wifi.conf"
  exit 1
fi

echo "[wifi] SSID=$SSID  COUNTRY=$COUNTRY"

# ── Apply config ───────────────────────────────────────────────────────────────
if command -v nmcli &>/dev/null && systemctl is-active --quiet NetworkManager 2>/dev/null; then
  # ── Bookworm / NetworkManager path ─────────────────────────────────────────
  echo "[wifi] detected NetworkManager"

  # Set country code
  raspi-config nonint do_wifi_country "$COUNTRY" 2>/dev/null || true

  # Remove any existing saved connection with the same name to avoid duplicates
  nmcli connection delete "hotspot-pi" &>/dev/null || true

  # Add the new WiFi connection
  nmcli connection add \
    type wifi \
    ifname wlan0 \
    con-name "hotspot-pi" \
    ssid "$SSID" \
    -- \
    wifi-sec.key-mgmt wpa-psk \
    wifi-sec.psk "$PASSWORD" \
    connection.autoconnect yes \
    connection.autoconnect-priority 10

  echo "[wifi] connecting..."
  nmcli connection up "hotspot-pi" && echo "[wifi] connected!" || echo "[wifi] will connect when hotspot is in range"

else
  # ── Bullseye / wpa_supplicant path ─────────────────────────────────────────
  echo "[wifi] detected wpa_supplicant"

  WPA_CONF=/etc/wpa_supplicant/wpa_supplicant.conf

  # Set country and ensure config file exists
  raspi-config nonint do_wifi_country "$COUNTRY" 2>/dev/null || true

  # Generate the network block (wpa_passphrase hashes the password securely)
  NETWORK_BLOCK=$(wpa_passphrase "$SSID" "$PASSWORD")

  # If this SSID is already in the file, replace its block; otherwise append.
  if grep -qF "ssid=\"$SSID\"" "$WPA_CONF" 2>/dev/null; then
    echo "[wifi] updating existing entry for $SSID"
    # Remove old block for this SSID (simple sed approach)
    python3 - "$WPA_CONF" "$SSID" <<'PYEOF'
import sys, re
path, ssid = sys.argv[1], sys.argv[2]
with open(path) as f:
    text = f.read()
# Remove any network{} block containing this ssid
pattern = r'network\s*\{[^}]*ssid="' + re.escape(ssid) + r'"[^}]*\}'
text = re.sub(pattern, '', text, flags=re.DOTALL)
with open(path, 'w') as f:
    f.write(text.strip() + '\n')
PYEOF
  fi

  # Append the new network block
  echo "" >> "$WPA_CONF"
  echo "$NETWORK_BLOCK" >> "$WPA_CONF"
  echo "[wifi] network block added to $WPA_CONF"

  # Reload wpa_supplicant and reconnect
  wpa_cli -i wlan0 reconfigure &>/dev/null || true
  sleep 3

  if wpa_cli -i wlan0 status | grep -q "wpa_state=COMPLETED"; then
    echo "[wifi] connected!"
  else
    echo "[wifi] will connect when hotspot is in range"
  fi
fi

echo "[wifi] done. Run 'ip addr show wlan0' to check your IP address."
