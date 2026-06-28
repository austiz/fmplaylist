#!/usr/bin/env bash
#
# whisper.sh — drive a running pi_fm_rds through its control pipe.
# Rotates RDS RT messages and (optionally) sprays the carrier across a list
# of FM channels. Launch pi_fm_rds first with `-ctl /tmp/rds_ctl`.
#
# Env vars:
#   CTL              control pipe path (default /tmp/rds_ctl)
#   MSG_INTERVAL_S   seconds between RT updates (default 15)
#   HOP_DWELL_MS     ms per channel; 0 disables hopping (default 0)
#   PS_TEXT          fixed PS shown on receivers (default WHISPER)

set -euo pipefail

CTL="${CTL:-/tmp/rds_ctl}"
MSG_INTERVAL_S="${MSG_INTERVAL_S:-15}"
HOP_DWELL_MS="${HOP_DWELL_MS:-0}"
PS_TEXT="${PS_TEXT:-WHISPER}"

MESSAGES=(
  "YOU LOOK GREAT"
  "GOOD ENERGY ONLY"
  "HAVE A NICE NIGHT"
  "SMILE TODAY"
)

# Curated empty-ish slots. Edit for your locale after scanning the band.
CHANNELS=(87.7 87.9 88.1 88.3 107.5 107.7 107.9)

if [[ ! -p "$CTL" ]]; then
  mkfifo "$CTL"
fi

# Hold the FIFO open from the writer side so the reader (which opens it
# O_RDONLY|O_NONBLOCK) doesn't see EOF between echoes.
exec 3>"$CTL"

echo "PS $PS_TEXT" >&3

if (( HOP_DWELL_MS > 0 )); then
  IFS=','; chans="${CHANNELS[*]}"; unset IFS
  echo "HOP ${chans}:${HOP_DWELL_MS}" >&3
  echo "whisper.sh: hopping ${#CHANNELS[@]} channels @ ${HOP_DWELL_MS} ms dwell"
else
  echo "whisper.sh: single channel (HOP_DWELL_MS=0)"
fi

while true; do
  MSG="${MESSAGES[RANDOM % ${#MESSAGES[@]}]}"
  echo "RT $MSG" >&3
  echo "whisper.sh: RT -> \"$MSG\""
  sleep "$MSG_INTERVAL_S"
done
