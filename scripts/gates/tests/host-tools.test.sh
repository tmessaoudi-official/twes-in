#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/host-tools.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: CI calls make, the Makefile keeps host tools only in its in-* targets.
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/.github/workflows"
  printf 'jobs:\n  api:\n    steps:\n      - uses: actions/checkout@v7\n      - run: make gate-api\n' > "$d/.github/workflows/ci.yml"
  printf 'gate-api: tools-image\n\t$(TOOLS) make --no-print-directory in-gate-api\nin-gate-api:\n\tcd api && composer gate\n\tphp -v\n\nup:\n\tdocker compose up -d\n' > "$d/Makefile"
  echo "$d"
}
run() { bash "$GATE" --root "$1" 2>&1; }
echo "host-tools gate"

d=$(tree); out=$(run "$d"); check "a tree whose host tools sit only in in-* targets passes" $? 0 "$out" "OK"

d=$(tree); printf '      - uses: shivammathur/setup-php@v2\n' >> "$d/.github/workflows/ci.yml"
out=$(run "$d"); check "setup-php in CI is refused" $? 1 "$out" "setup-php"

d=$(tree); printf '      - uses: actions/setup-node@v7\n' >> "$d/.github/workflows/ci.yml"
out=$(run "$d"); check "setup-node in CI is refused" $? 1 "$out" "setup-node"

d=$(tree); printf '      - run: composer install\n' >> "$d/.github/workflows/ci.yml"
out=$(run "$d"); check "a bare composer step in CI is refused" $? 1 "$out" "composer"

d=$(tree); printf '      - run: npx playwright test\n' >> "$d/.github/workflows/ci.yml"
out=$(run "$d"); check "a bare npx step in CI is refused" $? 1 "$out" "npx"

d=$(tree); printf '\ntest-api:\n\tcd api && vendor/bin/phpunit\n' >> "$d/Makefile"
out=$(run "$d"); check "a host phpunit in a public Makefile target is named with its target" $? 1 "$out" "test-api"

d=$(tree); printf '\nlint:\n\tnpm run lint\n' >> "$d/Makefile"
out=$(run "$d"); check "a host npm in a public Makefile target is refused" $? 1 "$out" "npm"

d=$(tree); printf '\nnotices: tools-image\n\t$(TOOLS) php scripts/n.php\n' >> "$d/Makefile"
out=$(run "$d"); check "a host tool inside a wrapper macro line passes" $? 0 "$out" "OK"

d=$(tree); rm "$d/Makefile"
out=$(run "$d"); check "a missing Makefile is refused, not skipped" $? 1 "$out" "found no Makefile"

d=$(tree); rm "$d/.github/workflows/ci.yml"
out=$(run "$d"); check "a missing CI file is refused, not skipped" $? 1 "$out" "found no"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
