#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/float-casts.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: PHP under api/src, amounts handled as decimal strings.
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/api/src/Money"
  printf '<?php\nfinal class Sum\n{\n    public function of(string $a, string $b): string\n    {\n        return bcadd($a, $b, 3);\n    }\n}\n' > "$d/api/src/Money/Sum.php"
  echo "$d"
}
run() { bash "$GATE" --root "$1" 2>&1; }
echo "float-casts gate"

d=$(tree); out=$(run "$d"); check "a tree with no float cast passes" $? 0 "$out" "OK"

d=$(tree); printf '<?php\n$x = number_format((float) $sum, 3);\n' > "$d/api/src/Money/Balance.php"
out=$(run "$d"); check "a (float) cast is refused with its file and line" $? 1 "$out" "Balance.php:2"

d=$(tree); printf '<?php\n$x = floatval($price);\n' > "$d/api/src/Money/Price.php"
out=$(run "$d"); check "floatval is refused" $? 1 "$out" "Price.php:2"

d=$(tree); printf '<?php\n$x = (double) $price;\n' > "$d/api/src/Money/Price.php"
out=$(run "$d"); check "a (double) cast is refused" $? 1 "$out" "Price.php:2"

d=$(tree); printf '<?php\n$x = ( float )$price;\n' > "$d/api/src/Money/Price.php"
out=$(run "$d"); check "a cast written with spaces is refused" $? 1 "$out" "Price.php:2"

d=$(tree); printf '<?php\n$x = (float) $cell; // float: a custom number field is stored as a JSON number\n' > "$d/api/src/Money/Cell.php"
out=$(run "$d"); check "a cast whose line says why it is not money passes" $? 0 "$out" "OK"

d=$(tree); printf '<?php\n$x = (float) $cell; // float:\n' > "$d/api/src/Money/Cell.php"
out=$(run "$d"); check "a reason left empty is no reason" $? 1 "$out" "Cell.php:2"

d=$(tree); printf '<?php\n/** Amounts never go through a (float) cast. */\n' > "$d/api/src/Money/Doc.php"
out=$(run "$d"); check "a cast named in a comment is not a cast" $? 0 "$out" "OK"

d=$(tree); rm -r "$d/api/src"
out=$(run "$d"); check "a missing api/src is refused, not skipped" $? 1 "$out" "found no"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
