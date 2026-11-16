#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d) || exit 1; export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/test-tmpdir.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A repository holding two tests that stop when their temporary folder cannot be made, and the runner that does too.
tree() {
  local d; d=$(mktemp -d) || return 1; mkdir -p "$d/scripts/gates/tests" "$d/scripts/tests"
  local good='TMPDIR=$(mktemp -d) || exit 1; export TMPDIR; trap '"'"'rm -rf "$TMPDIR"'"'"' EXIT'
  printf '#!/usr/bin/env bash\nset -uo pipefail\n%s\necho ok\n' "$good" > "$d/scripts/gates/tests/one.test.sh"
  printf '#!/usr/bin/env bash\n%s\necho ok\n' "$good" > "$d/scripts/tests/two.test.sh"
  printf '#!/usr/bin/env bash\nown=$(mktemp -d) || { echo "no folder" >&2; exit 2; }\n' > "$d/scripts/gates/tests/no-leftovers.sh"
  git -C "$d" init -q && git -C "$d" add -A
  echo "$d"
}
run() { bash "$GATE" --root "$1" --floor "${2:-2}" 2>&1; }
echo "test-tmpdir gate"

d=$(tree); out=$(run "$d"); check "tests that stop when mktemp fails pass" $? 0 "$out" "OK"

d=$(tree); sed -i 's/ || exit 1;/;/' "$d/scripts/tests/two.test.sh"
out=$(run "$d"); check "an unchecked TMPDIR=\$(mktemp -d) is refused with its file" $? 1 "$out" "scripts/tests/two.test.sh"

d=$(tree); printf '#!/usr/bin/env bash\necho no folder made\n' > "$d/scripts/gates/tests/one.test.sh"
out=$(run "$d"); check "a test with no temporary folder line is refused" $? 1 "$out" "one.test.sh"

d=$(tree); sed -i 's/ || exit 1;/ || true;/' "$d/scripts/gates/tests/one.test.sh"
out=$(run "$d"); check "  a check that goes on (|| true) is no check" $? 1 "$out" "one.test.sh"

d=$(tree); printf '#!/usr/bin/env bash\nown=$(mktemp -d)\n' > "$d/scripts/gates/tests/no-leftovers.sh"
out=$(run "$d"); check "the runner's own unchecked mktemp is refused too" $? 1 "$out" "no-leftovers.sh"

d=$(tree); printf '#!/usr/bin/env bash\necho untracked\n' > "$d/scripts/tests/three.test.sh"
out=$(run "$d"); check "a staged-only enumeration: an untracked test is not read" $? 0 "$out" "OK"

d=$(tree); out=$(run "$d" 3); check "fewer tests than the floor is refused, not read as all clean" $? 1 "$out" "floor"

# Tests elsewhere (infra/**/tests, scripts/<area>/tests) need no TMPDIR line, but any folder they make at the top
# stops them when it cannot be made.
d=$(tree); mkdir -p "$d/infra/web/tests"; printf '#!/usr/bin/env bash\ndir=$(mktemp -d)\nprintf x > "$dir/echo.conf"\n' > "$d/infra/web/tests/log.test.sh"; git -C "$d" add -A
out=$(run "$d"); check "an unchecked top-level dir=\$(mktemp -d) anywhere is refused" $? 1 "$out" "infra/web/tests/log.test.sh"

d=$(tree); mkdir -p "$d/scripts/release/tests"; printf '#!/usr/bin/env bash\nTMPDIR=$(mktemp -d); export TMPDIR\n' > "$d/scripts/release/tests/tag.test.sh"; git -C "$d" add -A
out=$(run "$d"); check "  an unchecked TMPDIR line outside the two folders too" $? 1 "$out" "scripts/release/tests/tag.test.sh"

d=$(tree); mkdir -p "$d/infra/web/tests"; printf '#!/usr/bin/env bash\ndir=$(mktemp -d) || exit 1\necho ok\n' > "$d/infra/web/tests/log.test.sh"; git -C "$d" add -A
out=$(run "$d"); check "  one that stops passes, with no TMPDIR line asked of it" $? 0 "$out" "OK"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
