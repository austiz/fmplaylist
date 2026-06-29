#!/usr/bin/env bash
# wifi_setup.sh — Connect Pi to a WiFi network with automatic rollback
# Called by pi_daemon.py when admin requests a WiFi change via the web UI.
#
# Usage: sudo bash wifi_setup.sh "SSID" "PASSWORD"
#        sudo bash wifi_setup.sh "OpenNet" ""   # open / no password
#
# Exit codes:
#   0 = connected successfully
#   1 = failed; previous network restored

set -eo pipefail

SSID="${1:-}"
PASSWORD="${2:-}"
IFACE="${3:-wlan0}"
TEST_HOST="8.8.8.8"
CONNECT_TIMEOUT=35  # seconds to wait for IP + internet

if [ -z "$SSID" ]; then
    echo "[wifi] ERROR: SSID required" >&2
    echo "  Usage: sudo bash wifi_setup.sh \"SSID\" \"PASSWORD\"" >&2
    exit 1
fi

echo "[wifi] Connecting to: \"$SSID\""

# ── Detect network manager ─────────────────────────────────────────────────────
USE_NM=false
if command -v nmcli &>/dev/null && systemctl is-active --quiet NetworkManager 2>/dev/null; then
    USE_NM=true
fi

# ── Wait for IP + internet ─────────────────────────────────────────────────────
wait_for_internet() {
    for i in $(seq 1 "$CONNECT_TIMEOUT"); do
        local ip
        ip=$(ip -4 addr show "$IFACE" 2>/dev/null | grep -oP '(?<=inet\s)\d+(\.\d+){3}' | head -1)
        if [ -n "$ip" ] && ping -c 2 -W 3 "$TEST_HOST" >/dev/null 2>&1; then
            echo "[wifi] ✓ Connected (IP: $ip)"
            return 0
        fi
        sleep 1
    done
    return 1
}

# ══════════════════════════════════════════════════════════════════════════════
# NetworkManager path (Raspberry Pi OS Bookworm+)
# ══════════════════════════════════════════════════════════════════════════════
if $USE_NM; then
    echo "[wifi] using NetworkManager"

    # Save current active connection for rollback
    PREV_CON=$(nmcli -t -f NAME,DEVICE connection show --active 2>/dev/null \
        | grep ":$IFACE" | head -1 | cut -d: -f1 || true)
    echo "[wifi] current connection: ${PREV_CON:-none}"

    # Remove any previous fmplaylist-managed connection
    nmcli connection delete "fmplaylist-wifi" &>/dev/null || true

    # Add the new connection
    if [ -n "$PASSWORD" ]; then
        nmcli connection add \
            type wifi ifname "$IFACE" con-name "fmplaylist-wifi" ssid "$SSID" \
            -- wifi-sec.key-mgmt wpa-psk wifi-sec.psk "$PASSWORD" \
            connection.autoconnect yes connection.autoconnect-priority 20
    else
        nmcli connection add \
            type wifi ifname "$IFACE" con-name "fmplaylist-wifi" ssid "$SSID" \
            -- wifi-sec.key-mgmt none \
            connection.autoconnect yes connection.autoconnect-priority 20
    fi

    echo "[wifi] activating..."
    nmcli connection up "fmplaylist-wifi" &>/dev/null || true
    sleep 5

    if wait_for_internet; then
        echo "[wifi] ✓ Connected to \"$SSID\""
        exit 0
    fi

    # ── Rollback ───────────────────────────────────────────────────────────────
    echo "[wifi] ✗ Failed — rolling back to \"${PREV_CON:-previous}\""
    nmcli connection delete "fmplaylist-wifi" &>/dev/null || true
    if [ -n "$PREV_CON" ]; then
        nmcli connection up "$PREV_CON" &>/dev/null || true
        sleep 5
    fi
    exit 1

# ══════════════════════════════════════════════════════════════════════════════
# wpa_supplicant path (Raspberry Pi OS Bullseye and earlier)
# ══════════════════════════════════════════════════════════════════════════════
else
    echo "[wifi] using wpa_supplicant"
    WPA_CONF="/etc/wpa_supplicant/wpa_supplicant.conf"
    BACKUP="/tmp/wpa_backup_$(date +%s).conf"
    cp "$WPA_CONF" "$BACKUP"

    rollback() {
        echo "[wifi] rolling back wpa_supplicant config..."
        cp "$BACKUP" "$WPA_CONF"
        wpa_cli -i "$IFACE" reconfigure >/dev/null 2>&1 || true
        sleep 8
        rm -f "$BACKUP"
        echo "[wifi] rollback complete"
    }

    # Write new network block using Python so quoting is always safe
    python3 - "$WPA_CONF" "$SSID" "$PASSWORD" <<'PYEOF'
import sys, re, subprocess

conf_path = sys.argv[1]
ssid      = sys.argv[2]
password  = sys.argv[3]

with open(conf_path) as f:
    content = f.read()

# Remove any existing block for this exact SSID to avoid duplicates
pattern = r'\nnetwork\s*=\s*\{[^}]*ssid\s*=\s*"' + re.escape(ssid) + r'"[^}]*\}'
content = re.sub(pattern, '', content, flags=re.DOTALL)

if password:
    r = subprocess.run(['wpa_passphrase', ssid, password], capture_output=True, text=True)
    if r.returncode != 0:
        print(f"[wifi] ERROR: wpa_passphrase failed: {r.stderr.strip()}", file=sys.stderr)
        sys.exit(1)
    # Strip comment lines that contain the plain-text password, then add priority
    lines = [l for l in r.stdout.splitlines() if not l.strip().startswith('#')]
    lines.insert(-1, '\tpriority=20')
    new_block = '\n' + '\n'.join(lines) + '\n'
else:
    new_block = f'\nnetwork={{\n\tssid="{ssid}"\n\tkey_mgmt=NONE\n\tpriority=20\n}}\n'

with open(conf_path, 'w') as f:
    f.write(content.rstrip() + new_block)

print(f'[wifi] network block written for: {ssid}')
PYEOF

    wpa_cli -i "$IFACE" reconfigure >/dev/null 2>&1 || true
    echo "[wifi] reconfiguring — waiting up to ${CONNECT_TIMEOUT}s..."

    if wait_for_internet; then
        echo "[wifi] ✓ Connected to \"$SSID\""
        rm -f "$BACKUP"
        exit 0
    fi

    echo "[wifi] ✗ Failed after ${CONNECT_TIMEOUT}s — rolling back"
    rollback
    exit 1
fi
