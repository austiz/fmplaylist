#!/usr/bin/env bash
# wifi_apply.sh — Manage the Pi's saved WiFi networks.
#
# Replaces wifi_setup.sh, which could only hold one network at a time (it
# deleted its own profile on every run).
#
# Modes:
#   sync            Reconcile all saved networks. Reads {"profiles":[...]} on
#                   stdin. Creates one autoconnect profile per entry with
#                   descending priority, so NetworkManager picks the best
#                   in-range network at boot with nothing else running.
#   connect SSID    Connect to one network now; password on stdin. Verifies and
#                   rolls back on failure.
#   cycle           Demote the current network below the others and re-select.
#                   Used by the daemon watchdog when an AP is associated but has
#                   no usable internet.
#
# Only profiles named fmplaylist-* are ever modified or deleted. The network
# used to provision the Pi is left alone deliberately, so a bad saved list can
# never strand the device.
#
# Exit codes: 0 = success, 1 = failure (previous network restored where applicable)

set -eo pipefail

MODE="${1:-}"
IFACE="${IFACE:-wlan0}"
TEST_HOST="8.8.8.8"
CONNECT_TIMEOUT=35          # seconds to wait for IP + internet
PROFILE_PREFIX="fmplaylist-"
BASE_PRIORITY=100           # first network gets this, each subsequent one less

if [ -z "$MODE" ]; then
    echo "[wifi] ERROR: mode required (sync | connect | cycle)" >&2
    exit 1
fi

USE_NM=false
if command -v nmcli &>/dev/null && systemctl is-active --quiet NetworkManager 2>/dev/null; then
    USE_NM=true
fi

WPA_CONF="/etc/wpa_supplicant/wpa_supplicant.conf"

# ── Shared helpers ────────────────────────────────────────────────────────────

wait_for_internet() {
    local i ip
    for i in $(seq 1 "$CONNECT_TIMEOUT"); do
        ip=$(ip -4 addr show "$IFACE" 2>/dev/null | grep -oP '(?<=inet\s)\d+(\.\d+){3}' | head -1)
        if [ -n "$ip" ] && ping -c 2 -W 3 "$TEST_HOST" >/dev/null 2>&1; then
            echo "[wifi] ✓ Connected (IP: $ip)"
            return 0
        fi
        sleep 1
    done
    return 1
}

current_ssid() {
    if $USE_NM; then
        nmcli -t -f ACTIVE,SSID device wifi list 2>/dev/null \
            | grep '^yes:' | head -1 | cut -d: -f2- || true
    else
        iwgetid -r 2>/dev/null || true
    fi
}

# Profile name for index N and SSID. Slug keeps it readable in `nmcli con show`;
# the zero-padded index keeps ordering obvious and guarantees uniqueness even
# when two SSIDs slugify identically.
profile_name() {
    local idx="$1" ssid="$2" slug
    slug=$(printf '%s' "$ssid" | tr -cs 'A-Za-z0-9' '-' | sed 's/^-//;s/-$//' | cut -c1-24)
    printf '%s%02d-%s' "$PROFILE_PREFIX" "$idx" "$slug"
}

# ══════════════════════════════════════════════════════════════════════════════
# sync — reconcile the full saved list
# ══════════════════════════════════════════════════════════════════════════════
do_sync() {
    local payload
    payload=$(cat)

    # Parse in Python: quoting SSIDs/PSKs safely in shell is a losing game, and
    # these strings come from admin input.
    local parsed
    parsed=$(printf '%s' "$payload" | python3 -c '
import json, sys
data = json.load(sys.stdin)
profiles = [p for p in data.get("profiles", []) if p.get("ssid")]
for i, p in enumerate(profiles):
    # TAB-separated: SSID and PSK may contain spaces, but never a tab or newline
    ssid = str(p["ssid"]).replace("\t", " ").replace("\n", " ")
    pw   = str(p.get("password") or "").replace("\t", " ").replace("\n", " ")
    print(f"{i}\t{ssid}\t{pw}")
') || {
        echo "[wifi] ERROR: could not parse profile list" >&2
        exit 1
    }

    if [ -z "$parsed" ]; then
        echo "[wifi] sync: empty list — leaving existing profiles alone"
        return 0
    fi

    if $USE_NM; then
        sync_nm "$parsed"
    else
        sync_wpa "$parsed"
    fi
}

sync_nm() {
    local parsed="$1"
    local keep_file
    keep_file=$(mktemp)
    # shellcheck disable=SC2064
    trap "rm -f '$keep_file'" RETURN

    local idx ssid pw name priority
    while IFS=$'\t' read -r idx ssid pw; do
        [ -n "$ssid" ] || continue
        name=$(profile_name "$idx" "$ssid")
        priority=$((BASE_PRIORITY - idx))
        echo "$name" >> "$keep_file"

        # Recreate rather than modify: an existing profile may carry stale
        # security settings (e.g. network changed from open to WPA2) that a
        # partial `nmcli con modify` would silently keep.
        nmcli connection delete "$name" &>/dev/null || true

        if [ -n "$pw" ]; then
            nmcli connection add \
                type wifi ifname "$IFACE" con-name "$name" ssid "$ssid" \
                -- wifi-sec.key-mgmt wpa-psk wifi-sec.psk "$pw" \
                connection.autoconnect yes \
                connection.autoconnect-priority "$priority" >/dev/null
        else
            nmcli connection add \
                type wifi ifname "$IFACE" con-name "$name" ssid "$ssid" \
                -- wifi-sec.key-mgmt none \
                connection.autoconnect yes \
                connection.autoconnect-priority "$priority" >/dev/null
        fi
        echo "[wifi] saved \"$ssid\" (priority $priority)"
    done <<< "$parsed"

    # Drop fmplaylist-* profiles no longer in the list. Anything not carrying
    # our prefix is left untouched — see header.
    local existing
    existing=$(nmcli -t -f NAME connection show 2>/dev/null | grep "^${PROFILE_PREFIX}" || true)
    while IFS= read -r name; do
        [ -n "$name" ] || continue
        if ! grep -Fxq "$name" "$keep_file"; then
            nmcli connection delete "$name" &>/dev/null || true
            echo "[wifi] removed stale profile: $name"
        fi
    done <<< "$existing"

    # If nothing is connected, give NM a nudge rather than waiting for its own
    # scan cycle — this is the common case right after boot.
    if [ -z "$(current_ssid)" ]; then
        echo "[wifi] not associated — asking NetworkManager to re-scan"
        nmcli device wifi rescan &>/dev/null || true
        sleep 3
        nmcli device connect "$IFACE" &>/dev/null || true
    fi
    return 0
}

sync_wpa() {
    local parsed="$1"
    echo "[wifi] using wpa_supplicant"
    cp "$WPA_CONF" "${WPA_CONF}.fmplaylist-bak" 2>/dev/null || true

    printf '%s' "$parsed" | python3 - "$WPA_CONF" "$BASE_PRIORITY" <<'PYEOF'
import re, subprocess, sys

conf_path, base_priority = sys.argv[1], int(sys.argv[2])

with open(conf_path) as f:
    content = f.read()

blocks = []
for line in sys.stdin.read().splitlines():
    if not line.strip():
        continue
    idx, ssid, pw = line.split('\t', 2)
    priority = base_priority - int(idx)

    # Drop any pre-existing block for this SSID so repeated syncs don't stack up
    pattern = r'\nnetwork\s*=\s*\{[^}]*ssid\s*=\s*"' + re.escape(ssid) + r'"[^}]*\}'
    content = re.sub(pattern, '', content, flags=re.DOTALL)

    if pw:
        r = subprocess.run(['wpa_passphrase', ssid, pw], capture_output=True, text=True)
        if r.returncode != 0:
            print(f'[wifi] ERROR: wpa_passphrase failed for {ssid}', file=sys.stderr)
            continue
        # Strip the comment line holding the plaintext password
        lines = [l for l in r.stdout.splitlines() if not l.strip().startswith('#')]
        lines.insert(-1, f'\tpriority={priority}')
        blocks.append('\n'.join(lines))
    else:
        blocks.append(
            f'network={{\n\tssid="{ssid}"\n\tkey_mgmt=NONE\n\tpriority={priority}\n}}'
        )
    print(f'[wifi] saved "{ssid}" (priority {priority})')

with open(conf_path, 'w') as f:
    f.write(content.rstrip() + '\n\n' + '\n\n'.join(blocks) + '\n')
PYEOF

    wpa_cli -i "$IFACE" reconfigure >/dev/null 2>&1 || true
    return 0
}

# ══════════════════════════════════════════════════════════════════════════════
# connect — one network, now, with rollback
# ══════════════════════════════════════════════════════════════════════════════
do_connect() {
    local ssid="${2:-}"
    if [ -z "$ssid" ]; then
        echo "[wifi] ERROR: connect requires an SSID" >&2
        exit 1
    fi

    local password=""
    IFS= read -r password || true

    echo "[wifi] Connecting to: \"$ssid\""

    if $USE_NM; then
        local prev_con name
        prev_con=$(nmcli -t -f NAME,DEVICE connection show --active 2>/dev/null \
            | grep ":$IFACE" | head -1 | cut -d: -f1 || true)
        echo "[wifi] current connection: ${prev_con:-none}"

        # Top priority so it also wins future autoconnect races.
        name="${PROFILE_PREFIX}00-$(printf '%s' "$ssid" | tr -cs 'A-Za-z0-9' '-' | cut -c1-24)"
        nmcli connection delete "$name" &>/dev/null || true

        if [ -n "$password" ]; then
            nmcli connection add type wifi ifname "$IFACE" con-name "$name" ssid "$ssid" \
                -- wifi-sec.key-mgmt wpa-psk wifi-sec.psk "$password" \
                connection.autoconnect yes \
                connection.autoconnect-priority "$BASE_PRIORITY" >/dev/null
        else
            nmcli connection add type wifi ifname "$IFACE" con-name "$name" ssid "$ssid" \
                -- wifi-sec.key-mgmt none \
                connection.autoconnect yes \
                connection.autoconnect-priority "$BASE_PRIORITY" >/dev/null
        fi

        echo "[wifi] activating..."
        nmcli connection up "$name" &>/dev/null || true
        sleep 5

        if wait_for_internet; then
            echo "[wifi] ✓ Connected to \"$ssid\""
            exit 0
        fi

        echo "[wifi] ✗ Failed — rolling back to \"${prev_con:-previous}\""
        nmcli connection delete "$name" &>/dev/null || true
        if [ -n "$prev_con" ]; then
            nmcli connection up "$prev_con" &>/dev/null || true
            sleep 5
        fi
        exit 1
    fi

    # wpa_supplicant path
    local backup="/tmp/wpa_backup_$(date +%s).conf"
    cp "$WPA_CONF" "$backup"

    python3 - "$WPA_CONF" "$ssid" "$password" <<'PYEOF'
import sys, re, subprocess

conf_path, ssid, password = sys.argv[1], sys.argv[2], sys.argv[3]

with open(conf_path) as f:
    content = f.read()

pattern = r'\nnetwork\s*=\s*\{[^}]*ssid\s*=\s*"' + re.escape(ssid) + r'"[^}]*\}'
content = re.sub(pattern, '', content, flags=re.DOTALL)

if password:
    r = subprocess.run(['wpa_passphrase', ssid, password], capture_output=True, text=True)
    if r.returncode != 0:
        print(f'[wifi] ERROR: wpa_passphrase failed: {r.stderr.strip()}', file=sys.stderr)
        sys.exit(1)
    lines = [l for l in r.stdout.splitlines() if not l.strip().startswith('#')]
    lines.insert(-1, '\tpriority=100')
    new_block = '\n' + '\n'.join(lines) + '\n'
else:
    new_block = f'\nnetwork={{\n\tssid="{ssid}"\n\tkey_mgmt=NONE\n\tpriority=100\n}}\n'

with open(conf_path, 'w') as f:
    f.write(content.rstrip() + new_block)

print(f'[wifi] network block written for: {ssid}')
PYEOF

    wpa_cli -i "$IFACE" reconfigure >/dev/null 2>&1 || true
    echo "[wifi] reconfiguring — waiting up to ${CONNECT_TIMEOUT}s..."

    if wait_for_internet; then
        echo "[wifi] ✓ Connected to \"$ssid\""
        rm -f "$backup"
        exit 0
    fi

    echo "[wifi] ✗ Failed after ${CONNECT_TIMEOUT}s — rolling back"
    cp "$backup" "$WPA_CONF"
    wpa_cli -i "$IFACE" reconfigure >/dev/null 2>&1 || true
    sleep 8
    rm -f "$backup"
    exit 1
}

# ══════════════════════════════════════════════════════════════════════════════
# cycle — current AP is associated but useless; try the next one
# ══════════════════════════════════════════════════════════════════════════════
do_cycle() {
    local ssid
    ssid=$(current_ssid)
    if [ -z "$ssid" ]; then
        echo "[wifi] cycle: not associated, nothing to demote"
        return 0
    fi

    if ! $USE_NM; then
        # wpa_supplicant has no per-connection priority juggling worth the
        # complexity here; a plain reassociate is the useful equivalent.
        echo "[wifi] cycle: reassociating (wpa_supplicant)"
        wpa_cli -i "$IFACE" reassociate >/dev/null 2>&1 || true
        return 0
    fi

    local active
    active=$(nmcli -t -f NAME,DEVICE connection show --active 2>/dev/null \
        | grep ":$IFACE" | head -1 | cut -d: -f1 || true)
    if [ -z "$active" ]; then
        echo "[wifi] cycle: no active profile on $IFACE"
        return 0
    fi

    echo "[wifi] cycle: demoting \"$active\" (SSID \"$ssid\") and re-selecting"

    # Only demote profiles we own. Demoting a user's own profile would be a
    # surprising, persistent change to config we did not create.
    case "$active" in
        "${PROFILE_PREFIX}"*)
            nmcli connection modify "$active" connection.autoconnect-priority -10 || true
            ;;
        *)
            echo "[wifi] cycle: \"$active\" is not fmplaylist-managed — not changing its priority"
            ;;
    esac

    nmcli connection down "$active" &>/dev/null || true
    nmcli device wifi rescan &>/dev/null || true
    sleep 5
    nmcli device connect "$IFACE" &>/dev/null || true

    if wait_for_internet; then
        echo "[wifi] cycle: ✓ now on \"$(current_ssid)\""
        return 0
    fi

    # Nothing better out there — restore the demoted profile so we don't leave
    # the Pi permanently avoiding a network that may recover later.
    case "$active" in
        "${PROFILE_PREFIX}"*)
            echo "[wifi] cycle: no better network found — restoring \"$active\""
            nmcli connection modify "$active" connection.autoconnect-priority "$BASE_PRIORITY" || true
            nmcli connection up "$active" &>/dev/null || true
            ;;
    esac
    return 0
}

case "$MODE" in
    sync)    do_sync ;;
    connect) do_connect "$@" ;;
    cycle)   do_cycle ;;
    *)
        echo "[wifi] ERROR: unknown mode \"$MODE\" (expected sync | connect | cycle)" >&2
        exit 1
        ;;
esac
