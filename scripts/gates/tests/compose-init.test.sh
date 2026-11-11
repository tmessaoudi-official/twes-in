#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
GATE=$HERE/compose-init.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A server's and a shop's files as they are in the tree, each case changing one thing in its own copy.
project() {
  local d; d=$(mktemp -d)
  cp "$ROOT"/compose*.yaml "$d/"
  echo "$d"
}
edit() { sed -i "$2" "$1"; }

echo "compose-init gate"
d=$(project)
out=$(bash "$GATE" --root "$d" 2>&1); check "a server and a shop as shaped pass" $? 0 "$out" "OK — "

# A failed asynchronous archive-push, its bucket unreachable, reset every connection every 12 seconds while postgres
# was PID 1: the archiving database runs under an init.
d=$(project); edit "$d/compose.backup.yaml" '/^    init: true/d'
out=$(bash "$GATE" --root "$d" 2>&1); check "the archiving database without an init is named on a server" $? 1 "$out" "server: postgres archives its WAL"
check "  and on a shop" 1 1 "$out" "shop: postgres archives its WAL"

d=$(project); edit "$d/compose.backup.yaml" 's/^    init: true/    init: false/'
out=$(bash "$GATE" --root "$d" 2>&1); check "init: false is named as no init" $? 1 "$out" "server: postgres archives its WAL"

# The check passes over nothing if no service archives: an installation reading none is a failure, not a pass.
d=$(project); edit "$d/compose.backup.yaml" '/archive_command=/d'
out=$(bash "$GATE" --root "$d" 2>&1); check "an installation where nothing archives fails" $? 1 "$out" "server: no service archives its WAL"

d=$(project); rm "$d/compose.backup-local.yaml"
out=$(bash "$GATE" --root "$d" 2>&1); check "an installation whose file is missing fails" $? 1 "$out" "shop: found no compose.backup-local.yaml"

echo "compose-init gate: $pass passed, $fail failed"
((fail == 0))
