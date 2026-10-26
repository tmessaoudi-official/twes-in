#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/domain-refusals.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: processors answering through DomainRule, as many as the gate's floor asks.
tree() {
  local d i; d=$(mktemp -d); mkdir -p "$d/api/src/Shop"
  for i in $(seq 1 90); do
    printf '<?php\nthrow DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason ?? %s, $refused->params, $refused);\n' "'invalid_value'" > "$d/api/src/Shop/P$i.php"
  done
  echo "$d"
}
run() { bash "$GATE" --root "$1" 2>&1; }
echo "domain-refusals gate"

d=$(tree); out=$(run "$d"); check "a tree answering every refusal as a violation passes" $? 0 "$out" "OK"

d=$(tree); printf '<?php\nthrow new UnprocessableEntityHttpException(\\sprintf(%s, $refused->field, $refused->getMessage()), $refused);\n' "'%s: %s'" > "$d/api/src/Shop/Plain.php"
out=$(run "$d"); check "a plain « field: message » 422 is refused with its file and line" $? 1 "$out" "Plain.php:2"

d=$(tree); printf '<?php\nthrow new UnprocessableEntityHttpException(\\sprintf(%s, $at, $refused->field, $refused->getMessage()), $refused);\n' "'%s.%s: %s'" > "$d/api/src/Shop/Nested.php"
out=$(run "$d"); check "a field named under a parent is refused too" $? 1 "$out" "Nested.php:2"

d=$(tree); printf '<?php\nthrow new UnprocessableEntityHttpException(\\sprintf(%s, Role::BUILT_IN, $refused->getMessage()), $refused); // plain-refusal: a token the page reads\n' "'%s: %s'" > "$d/api/src/Shop/Token.php"
out=$(run "$d"); check "a 422 whose line says why it is no field's refusal passes" $? 0 "$out" "OK"

d=$(tree); printf '<?php\nthrow new UnprocessableEntityHttpException(\\sprintf(%s, Role::BUILT_IN, $refused->getMessage()), $refused); // plain-refusal:\n' "'%s: %s'" > "$d/api/src/Shop/Token.php"
out=$(run "$d"); check "a reason left empty is no reason" $? 1 "$out" "Token.php:2"

d=$(tree); printf '<?php\n/** Never UnprocessableEntityHttpException(\\sprintf(%s, …) here. */\n' "'%s: %s'" > "$d/api/src/Shop/Doc.php"
out=$(run "$d"); check "an answer named in a comment is not one" $? 0 "$out" "OK"

d=$(tree); rm "$d/api/src/Shop/P1.php"
out=$(run "$d"); check "fewer DomainRule answers than the floor is refused" $? 1 "$out" "fewer than the 90"

d=$(tree); rm -r "$d/api/src"
out=$(run "$d"); check "a missing api/src is refused, not skipped" $? 1 "$out" "found no"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
