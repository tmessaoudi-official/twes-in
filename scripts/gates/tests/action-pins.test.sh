#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
GATE=$HERE/action-pins.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
SHA=3d3c42e5aac5ba805825da76410c181273ba90b1
workflow() { # uses line(s)
  local d; d=$(mktemp -d); mkdir -p "$d/.github/workflows"
  printf 'jobs:\n  a:\n    steps:\n' > "$d/.github/workflows/ci.yml"
  for use in "$@"; do printf '      - uses: %s\n' "$use" >> "$d/.github/workflows/ci.yml"; done
  echo "$d"
}

echo "action-pins gate"
d=$(workflow "actions/checkout@$SHA # v7.0.1" "./.github/actions/local")
out=$(bash "$GATE" --root "$d" 2>&1); check "an action pinned by commit, its release named, passes" $? 0 "$out" "OK — 1 uses"

d=$(workflow "actions/checkout@v7")
out=$(bash "$GATE" --root "$d" 2>&1); check "an action pinned by a tag is named" $? 1 "$out" "ci.yml:4 uses actions/checkout@v7"

d=$(workflow "actions/checkout@$SHA")
out=$(bash "$GATE" --root "$d" 2>&1); check "a commit without its release named is refused" $? 1 "$out" "actions/checkout@$SHA"

d=$(workflow "actions/checkout@${SHA:0:12} # v7.0.1")
out=$(bash "$GATE" --root "$d" 2>&1); check "a short SHA is refused" $? 1 "$out" "pin it by the release's full commit SHA"

d=$(workflow); : > "$d/.github/workflows/ci.yml"
out=$(bash "$GATE" --root "$d" 2>&1); check "workflows using no action fail rather than pass on nothing" $? 1 "$out" "reads nothing"

echo "action-pins gate: $pass passed, $fail failed"
((fail == 0))
