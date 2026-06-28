#!/usr/bin/env bash
#
# run.sh — FM-RDS station: looped audio + rotating positive RT.
# Standalone mode — no web app needed. Loops one WAV indefinitely.
#
# Usage:
#   ./run.sh
#   FREQ=96.9 PS_TEXT="PIRATE  " SONG="mysong.wav" ./run.sh

set -uo pipefail

cd "$(dirname "$(readlink -f "$0")")"

FREQ="${FREQ:-96.9}"
PS_TEXT="${PS_TEXT:-96.9 FM }"   # exactly 8 chars for RDS PS field
PI_CODE="${PI_CODE:-C0DE}"
MSG_INTERVAL_S="${MSG_INTERVAL_S:-20}"
SONG="${SONG:-FTPA.wav}"
CTL=/tmp/rds_ctl

MESSAGES=(
  "HI VISTA, BE KIND TO YOURSELF"
)

if [[ $EUID -ne 0 ]]; then
  exec sudo -E bash "$0" "$@"
fi

[[ -x ./pi_fm_rds ]] || { echo "pi_fm_rds not built — run 'make' first."; exit 1; }
[[ -f "$SONG" ]] || { echo "Audio file $SONG not found in $(pwd)."; exit 1; }
command -v ffmpeg >/dev/null || { echo "ffmpeg not installed."; exit 1; }

rm -f "$CTL"
mkfifo -m 0666 "$CTL"

# ffmpeg loops the song forever and emits raw WAV at the rate pi_fm_rds wants;
# pi_fm_rds reads from stdin via -audio -.
ffmpeg -hide_banner -loglevel error -stream_loop -1 -i "$SONG" \
       -f wav -ar 44100 -ac 2 -sample_fmt s16 - | \
./pi_fm_rds -freq "$FREQ" -pi "$PI_CODE" -audio - \
            -ps "$PS_TEXT" -rt "BOOTING" -ctl "$CTL" &
TX_PID=$!

exec 3>"$CTL"

CLEANED=0
cleanup() {
  [[ $CLEANED -eq 1 ]] && return
  CLEANED=1
  echo
  echo "[run.sh] stopping..."
  exec 3>&- 2>/dev/null || true
  pkill -INT -P $$ 2>/dev/null || true
  sleep 1
  pkill -P $$ 2>/dev/null || true
  wait 2>/dev/null || true
  rm -f "$CTL"
  echo "[run.sh] done."
}
trap cleanup EXIT INT TERM

sleep 2

while kill -0 "$TX_PID" 2>/dev/null; do
  MSG="${MESSAGES[RANDOM % ${#MESSAGES[@]}]}"
  echo "RT $MSG" >&3
  sleep "$MSG_INTERVAL_S"
done
