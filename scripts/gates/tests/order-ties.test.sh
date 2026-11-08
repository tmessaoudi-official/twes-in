#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/order-ties.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: three orders, each tied by its id (the floor the cases run with is 3).
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/api/src/Things"
  cat > "$d/api/src/Things/Things.php" <<'PHP'
<?php
final class Things
{
    public function all($qb, $connection)
    {
        $qb->orderBy('t.createdAt', \SortDirection::Descending)->addOrderBy('t.id', \SortDirection::Descending);
        $connection->fetchAllAssociative('SELECT id FROM thing ORDER BY created_at DESC, id DESC LIMIT 5');
        return $connection->fetchAllAssociative('SELECT SUM(x) AS total FROM thing GROUP BY t.code ORDER BY SUM(x) DESC, t.code');
    }
}
PHP
  echo "$d"
}
run() { ORDER_TIES_FLOOR=3 bash "$GATE" --root "$1" 2>&1; }
echo "order-ties gate"

d=$(tree); out=$(run "$d"); check "a tree whose every order ends on a key that ties nothing passes" $? 0 "$out" "OK"

d=$(tree); printf "<?php\n\$qb->select('g')\n    ->orderBy('g.endsAt', \\\\SortDirection::Descending)\n    ->getQuery();\n" > "$d/api/src/Things/Grants.php"
out=$(run "$d"); check "a builder chain ending on a moment is refused with its file and line" $? 1 "$out" "Grants.php:3"

d=$(tree); printf "<?php\n\$qb->orderBy('t.id')\n    ->addOrderBy('t.failedAt');\n" > "$d/api/src/Things/Late.php"
out=$(run "$d"); check "a moment added last to a chain is refused, whatever came before" $? 1 "$out" "Late.php:3"

d=$(tree); printf "<?php\n\$sql = 'SELECT id FROM sandbox WHERE state = ? ORDER BY build_started_at';\n" > "$d/api/src/Things/Raw.php"
out=$(run "$d"); check "raw SQL ending on a timestamp column is refused" $? 1 "$out" "Raw.php:2"

d=$(tree); printf "<?php\n\$dql = 'SELECT u FROM User u ORDER BY m.createdAt ASC LIMIT 3';\n" > "$d/api/src/Things/Dql.php"
out=$(run "$d"); check "a DQL string ending on a moment with its direction is refused" $? 1 "$out" "Dql.php:2"

d=$(tree); printf "<?php\n\$sql = 'SELECT d FROM doc d ORDER BY d.issue_date';\n" > "$d/api/src/Things/Days.php"
out=$(run "$d"); check "a day column is a moment too: two documents share it" $? 1 "$out" "Days.php:2"

d=$(tree); printf "<?php\n\$sql = 'SELECT a, (SELECT name FROM c ORDER BY c.at LIMIT 1) FROM a ORDER BY a.id';\n" > "$d/api/src/Things/Inner.php"
out=$(run "$d"); check "an inner query's order is read on its own, inside its parentheses" $? 1 "$out" "Inner.php:2"

d=$(tree); printf "<?php\n// order-tie: one row a day, the query groups by it\n\$sql = 'SELECT day, SUM(x) FROM t GROUP BY day ORDER BY day';\n" > "$d/api/src/Things/Said.php"
out=$(run "$d"); check "an order that says why its moment ties nothing, on the line above, passes" $? 0 "$out" "OK"

d=$(tree); printf "<?php\n// The list is read ORDER BY created_at, then by id.\n" > "$d/api/src/Things/Comment.php"
out=$(run "$d"); check "a comment that names an order is not one" $? 0 "$out" "OK"

d=$(tree); out=$(ORDER_TIES_FLOOR=4 bash "$GATE" --root "$d" 2>&1); check "under the floor of orders read, the gate is blind and reds" $? 1 "$out" "floor"

d=$(mktemp -d); out=$(run "$d"); check "a tree with no api/src reds" $? 1 "$out" "no api/src"

echo "order-ties: $pass passed, $fail failed"
((fail == 0))
