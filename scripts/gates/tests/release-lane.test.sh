#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
GATE=$HERE/release-lane.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
workflows() { # name=branches-line pairs; a value of « none » writes a workflow with no push trigger
  local d pair; d=$(mktemp -d); mkdir -p "$d/.github/workflows"
  for pair in "$@"; do
    if [[ "${pair#*=}" == none ]]; then printf 'on:\n  schedule:\n    - cron: "1 1 * * 1"\n' > "$d/.github/workflows/${pair%%=*}.yml"
    else printf 'on:\n  push:\n    branches: %s\n    paths:\n      - x\n' "${pair#*=}" > "$d/.github/workflows/${pair%%=*}.yml"; fi
  done
  echo "$d"
}

echo "release-lane gate"
d=$(workflows "ci=[master, 'release/**']" "drill=[master, 'claude/**', 'release/**']" "weekly=none")
out=$(bash "$GATE" --root "$d" 2>&1); check "every workflow master pushes run also runs on a release's branch: passes" $? 0 "$out" "OK — 2 workflows"

d=$(workflows "ci=[master, 'release/**']" "deploy-check=[master]")
out=$(bash "$GATE" --root "$d" 2>&1); check "one master alone runs is named" $? 1 "$out" "deploy-check.yml"

d=$(workflows "ci=[master, 'release/*']")
out=$(bash "$GATE" --root "$d" 2>&1); check "a pattern that misses release/2026.11.30 is refused" $? 1 "$out" "ci.yml"

d=$(workflows "weekly=none")
out=$(bash "$GATE" --root "$d" 2>&1); check "no workflow master pushes run: fails rather than passing on nothing" $? 1 "$out" "reads nothing"

echo "release-lane gate: $pass passed, $fail failed"
((fail == 0))
