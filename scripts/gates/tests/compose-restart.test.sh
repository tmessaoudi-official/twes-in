#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
GATE=$HERE/compose-restart.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# Every installation's files as they are in the tree, each case changing one thing in its own copy.
project() {
  local d; d=$(mktemp -d)
  cp "$ROOT"/compose*.yaml "$d/"
  mkdir -p "$d/infra/monitor"; cp "$ROOT/infra/monitor/compose.yaml" "$d/infra/monitor/"
  echo "$d"
}
edit() { sed -i "$2" "$1"; }

echo "compose-restart gate"
d=$(project)
out=$(bash "$GATE" --root "$d" 2>&1); check "every installation as shaped passes" $? 0 "$out" "OK — "

# Development's api died one evening and stayed down while its workers came back: a running service says it restarts.
d=$(project); edit "$d/compose.yaml" '/^  api:$/,/^    restart: unless-stopped$/{/^    restart: unless-stopped$/d}'
out=$(bash "$GATE" --root "$d" 2>&1); check "development's api saying nothing of a restart is named" $? 1 "$out" "development: api says nothing of a restart"
check "  and from the images too" 1 1 "$out" "images: api says nothing of a restart"

d=$(project); edit "$d/compose.yaml" '0,/^    restart: "no"  # a job: it migrates, grants and exits$/s//    restart: on-failure/'
out=$(bash "$GATE" --root "$d" 2>&1); check "a policy other than the two is named" $? 1 "$out" "migrate restarts « on-failure »"

d=$(project); edit "$d/infra/monitor/compose.yaml" '/^  gatus:$/,/^    restart: unless-stopped$/{/^    restart: unless-stopped$/d}'
out=$(bash "$GATE" --root "$d" 2>&1); check "the monitor's gatus saying nothing is named" $? 1 "$out" "monitor: gatus says nothing of a restart"

d=$(project); rm "$d/compose.onprem.yaml"
out=$(bash "$GATE" --root "$d" 2>&1); check "an installation whose file is missing fails" $? 1 "$out" "shop: found no compose.onprem.yaml"

echo "compose-restart gate: $pass passed, $fail failed"
((fail == 0))
