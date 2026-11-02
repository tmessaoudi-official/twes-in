#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# staging-follow-interval.sh: how often staging asks the registry is an install value (STAGING_FOLLOW_INTERVAL in the
# values staging-follow.env holds), written as a drop-in over the timer; five minutes when unset, as the timer itself says,
# and refused below a minute or in any form the script does not read.
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
SCRIPT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/staging-follow-interval.sh
TIMER=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)/infra/staging/twes-staging-follow.timer
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

# Runs the script over an env file holding $1 (nothing at all when empty), into a drop-in directory of its own; prints its
# output, then the drop-in it wrote (or « no drop-in »).
run() {
  local d; d=$(mktemp -d)
  printf 'TWES_CHECKOUT=/srv/twes-in\n%s' "$1" > "$d/staging-follow.env"
  local out status
  out=$(bash "$SCRIPT" "$d/staging-follow.env" "$d/twes-staging-follow.timer.d" 2>&1); status=$?
  if [[ -f "$d/twes-staging-follow.timer.d/interval.conf" ]]; then out+=$'\n'$(cat "$d/twes-staging-follow.timer.d/interval.conf"); else out+=$'\nno drop-in'; fi
  echo "$out"
  return $status
}

# The timer ships five minutes; the value left unset must be the same, so installing the drop-in changes nothing.
shipped=$(sed -n 's/^OnUnitInactiveSec=//p' "$TIMER")
check "the timer ships five minutes" 0 0 "$shipped" "5min"

out=$(run ''); check "unset is five minutes, as the timer says" $? 0 "$out" $'OnUnitInactiveSec=\nOnUnitInactiveSec=5min'
out=$(run $'STAGING_FOLLOW_INTERVAL=\n'); check "empty is five minutes" $? 0 "$out" $'OnUnitInactiveSec=\nOnUnitInactiveSec=5min'
out=$(run $'STAGING_FOLLOW_INTERVAL=1min\n'); check "a minute, the floor, is kept" $? 0 "$out" "OnUnitInactiveSec=1min"
out=$(run $'STAGING_FOLLOW_INTERVAL=60s\n'); check "sixty seconds is a minute, kept" $? 0 "$out" "OnUnitInactiveSec=60s"
out=$(run $'STAGING_FOLLOW_INTERVAL=2h\n'); check "two hours is kept" $? 0 "$out" "OnUnitInactiveSec=2h"
out=$(run $'STAGING_FOLLOW_INTERVAL=3min\nSTAGING_FOLLOW_INTERVAL=10min\n'); check "the last line wins, as systemd reads an env file" $? 0 "$out" "OnUnitInactiveSec=10min"
out=$(run $'STAGING_FOLLOW_INTERVAL=59s\n'); check "below a minute is refused" $? 1 "$out" "no drop-in"
out=$(run $'STAGING_FOLLOW_INTERVAL=59s\n'); check "the refusal names the floor" $? 1 "$out" "at least 1min"
out=$(run $'STAGING_FOLLOW_INTERVAL=0min\n'); check "no wait at all is refused" $? 1 "$out" "no drop-in"
out=$(run $'STAGING_FOLLOW_INTERVAL=5 min\n'); check "a form systemd may read but this does not is refused" $? 1 "$out" "no drop-in"
out=$(run $'STAGING_FOLLOW_INTERVAL=5m\n'); check "« m », which this does not read, is refused" $? 1 "$out" "no drop-in"
out=$(run $'STAGING_FOLLOW_INTERVAL=5min;reboot\n'); check "nothing but the value reaches the drop-in" $? 1 "$out" "no drop-in"
out=$(bash "$SCRIPT" 2>&1); check "the env file and the drop-in directory must be named" $? 2 "$out" "Usage"

echo "staging-follow-interval.test: $pass passed, $fail failed"
[[ $fail -eq 0 ]]
