#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/sort-directions.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: repositories under api/src ordering through SortDirection, three times (the floor
# the cases run with is 3).
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/api/src/Things" "$d/api/tests"
  printf '<?php\nfinal class Things\n{\n    public function all($qb)\n    {\n        return $qb->orderBy(%s)->addOrderBy(%s)->addOrderBy(%s);\n    }\n}\n' \
    "'t.name', \\SortDirection::Ascending" "'t.at', \\SortDirection::Descending" "'t.id', \\SortDirection::Ascending" > "$d/api/src/Things/Things.php"
  echo "$d"
}
run() { SORT_DIRECTIONS_FLOOR=3 bash "$GATE" --root "$1" 2>&1; }
echo "sort-directions gate"

d=$(tree); out=$(run "$d"); check "a tree ordering through SortDirection passes" $? 0 "$out" "OK"

d=$(tree); printf "<?php\n\$qb->orderBy('t.name', 'ASC');\n" > "$d/api/src/Things/Names.php"
out=$(run "$d"); check "a string direction to orderBy is refused with its file and line" $? 1 "$out" "Names.php:2"

d=$(tree); printf "<?php\n\$qb->where('x')->addOrderBy('IDENTITY(t.place)', 'desc');\n" > "$d/api/src/Things/Places.php"
out=$(run "$d"); check "a lowercase direction to addOrderBy, after a call in its sort, is refused" $? 1 "$out" "Places.php:2"

d=$(tree); printf "<?php\n\$qb->orderBy(\"t.name\", \"DESC\");\n" > "$d/api/src/Things/Quoted.php"
out=$(run "$d"); check "a direction in double quotes is refused" $? 1 "$out" "Quoted.php:2"

d=$(tree); printf "<?php\nclass Note\n{\n    #[ORM\\\\OrderBy(['position' => 'ASC'])]\n    public \$lines;\n}\n" > "$d/api/src/Things/Note.php"
out=$(run "$d"); check "a string direction in an ORM OrderBy mapping is refused" $? 1 "$out" "Note.php:4"

d=$(tree); printf "<?php\n\$order = new Expr\\\\OrderBy('t.name', 'ASC');\n" > "$d/api/src/Things/Expr.php"
out=$(run "$d"); check "a string direction to Expr OrderBy is refused" $? 1 "$out" "Expr.php:2"

d=$(tree); printf "<?php\n\$qb->orderBy('t.name', null);\n" > "$d/api/src/Things/Nothing.php"
out=$(run "$d"); check "an explicit null direction is refused" $? 1 "$out" "Nothing.php:2"

d=$(tree); printf "<?php\n\$qb->orderBy('t.name', 'ASC');\n" > "$d/api/tests/ThingsTest.php"
out=$(run "$d"); check "a test's query builder is held to it too" $? 1 "$out" "ThingsTest.php:2"

d=$(tree); printf "<?php\n\$qb->orderBy('t.name')->addOrderBy('t.id');\n" > "$d/api/src/Things/Plain.php"
out=$(run "$d"); check "an order given no direction passes (Doctrine reads it as ascending)" $? 0 "$out" "OK"

d=$(tree); printf "<?php\n\$qb->addOrderBy(\$column, \$direction);\n" > "$d/api/src/Things/Asked.php"
out=$(run "$d"); check "a direction held in a variable passes (its type says what it is)" $? 0 "$out" "OK"

d=$(tree); printf "<?php\n\$found = \$repository->findBy(['company' => \$id], ['name' => 'ASC']);\n" > "$d/api/src/Things/Found.php"
out=$(run "$d"); check "findBy's string order passes: Doctrine 3 takes it without a deprecation" $? 0 "$out" "OK"

d=$(tree); printf "<?php\n\$sql->orderBy('a.at', 'DESC'); // sort-direction: DBAL's query builder takes a string\n" > "$d/api/src/Things/Plain.php"
out=$(run "$d"); check "a line that says why it keeps a string passes" $? 0 "$out" "OK"

d=$(tree); printf "<?php\n\$sql->orderBy('a.at', 'DESC'); // sort-direction:\n" > "$d/api/src/Things/Plain.php"
out=$(run "$d"); check "a reason left empty is no reason" $? 1 "$out" "Plain.php:2"

d=$(tree); printf "<?php\n/** Ordered as orderBy('t.name', 'ASC') would. */\n" > "$d/api/src/Things/Doc.php"
out=$(run "$d"); check "an order named in a comment is not one" $? 0 "$out" "OK"

d=$(tree); printf '<?php\n' > "$d/api/src/Things/Things.php"
out=$(run "$d"); check "fewer SortDirection uses than the floor is refused: the gate went blind" $? 1 "$out" "floor"

d=$(tree); rm -r "$d/api/src"
out=$(run "$d"); check "a missing api/src is refused, not skipped" $? 1 "$out" "found no"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
