#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/test-issue-shortcut.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: a test issuing through the API, and one shortcut that says why.
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/api/tests/Functional"
  cat > "$d/api/tests/Functional/IssueTest.php" <<'PHP'
<?php
$this->postJson($this->path("invoices/$id/issue"), null);
// issue-shortcut: the merge engine reads the state, and these customers lack the identity an issue needs
$this->em()->getConnection()->executeStatement("UPDATE invoice SET status = 'issued' WHERE id = ?", [$id]);
$this->em()->getConnection()->executeStatement("UPDATE invoice SET customer_id = ? WHERE id = ?", [$c, $id]);
PHP
  echo "$d"
}
add() { printf '<?php\n%s\n' "$2" > "$1"; }
run() { bash "$GATE" --root "$1" --floor 1 2>&1; }
echo "test-issue-shortcut gate"

d=$(tree); out=$(run "$d"); check "an issue through the API and a shortcut that says why pass" $? 0 "$out" "OK — 1"

d=$(tree); add "$d/api/tests/Functional/Bare.php" "\$c->executeStatement(\"UPDATE invoice SET status = 'issued', number = 'F-1' WHERE id = ?\", [\$id]);"
out=$(run "$d"); check "an invoice issued by SQL is refused with its file and line" $? 1 "$out" "Bare.php:2"

d=$(tree); add "$d/api/tests/Functional/Quote.php" "\$c->executeStatement(\"UPDATE quote SET number = 'D-1', status = 'sent' WHERE id = ?\", [\$id]);"
out=$(run "$d"); check "a quote sent by SQL is refused, status set after another column" $? 1 "$out" "Quote.php:2"

d=$(tree); add "$d/api/tests/Functional/Note.php" "\$c->executeStatement('UPDATE delivery_note SET status = ? WHERE id = ?', ['validated', \$id]);"
out=$(run "$d"); check "a delivery note validated through a bound status is refused" $? 1 "$out" "Note.php:2"

d=$(tree); add "$d/api/tests/Functional/Order.php" "\$c->executeStatement(\"update purchase_order set status='sent' where id = ?\", [\$id]);"
out=$(run "$d"); check "an order sent by SQL in lower case is refused" $? 1 "$out" "Order.php:2"

d=$(tree); add "$d/api/tests/Functional/Insert.php" "\$c->executeStatement(\"INSERT INTO invoice (id, status) VALUES (?, 'issued')\", [\$id]);"
out=$(run "$d"); check "an invoice inserted with a status is refused" $? 1 "$out" "Insert.php:2"

d=$(tree); add "$d/api/tests/Functional/Other.php" "\$c->executeStatement(\"UPDATE payment_declaration SET status = 'rejected' WHERE id = ?\", [\$id]);
\$c->executeStatement(\"UPDATE invoice_line SET status = 'x' WHERE id = ?\", [\$id]);"
out=$(run "$d"); check "another table's status is not a document's" $? 0 "$out" "OK"

d=$(tree); add "$d/api/tests/Functional/Where.php" "\$c->executeStatement(\"UPDATE invoice SET number = 'F-X' WHERE id = (SELECT id FROM invoice WHERE status = 'cancelled' LIMIT 1)\");"
out=$(run "$d"); check "a status the WHERE reads is not one the SET writes" $? 0 "$out" "OK"

d=$(tree); add "$d/api/tests/Functional/Read.php" "\$n = \$c->fetchOne(\"SELECT COUNT(*) FROM invoice WHERE status = 'issued'\");"
out=$(run "$d"); check "reading a status sets nothing" $? 0 "$out" "OK"

d=$(tree); add "$d/api/tests/Functional/Same.php" "\$c->executeStatement(\"UPDATE invoice SET status = 'paid' WHERE id = ?\", [\$id]); // issue-shortcut: a paid invoice still owed, which no use case makes"
out=$(run "$d"); check "a reason on the line passes" $? 0 "$out" "OK — 2"

d=$(tree); add "$d/api/tests/Functional/Empty.php" "\$c->executeStatement(\"UPDATE invoice SET status = 'paid' WHERE id = ?\", [\$id]); // issue-shortcut:"
out=$(run "$d"); check "a reason left empty is no reason" $? 1 "$out" "Empty.php:2"

d=$(tree); add "$d/api/tests/Functional/Dbal.php" "\$this->em()->getConnection()->update('purchase_order', ['status' => 'sent'], ['id' => \$id]);"
out=$(run "$d"); check "an order sent through DBAL's table API is refused" $? 1 "$out" "Dbal.php:2"

d=$(tree); add "$d/api/tests/Functional/DbalInsert.php" "\$c->insert(\"invoice\", ['id' => \$id, 'number' => 'F-1', \"status\" => 'issued']);"
out=$(run "$d"); check "an invoice inserted through DBAL's table API with a status is refused" $? 1 "$out" "DbalInsert.php:2"

d=$(tree); add "$d/api/tests/Functional/DbalLines.php" "\$c->insert('quote', [
    'id' => \$id,
    'status' => 'sent',
]);"
out=$(run "$d"); check "a table-API call whose array runs over several lines is read whole" $? 1 "$out" "DbalLines.php:2"

d=$(tree); add "$d/api/tests/Functional/DbalSaid.php" "// issue-shortcut: sending prints and numbers the order, which is not what is tested here
\$c->update('purchase_order', ['status' => 'sent'], ['id' => \$id]);"
out=$(run "$d"); check "a table-API shortcut that says why passes" $? 0 "$out" "OK — 2"

d=$(tree); add "$d/api/tests/Functional/DbalOther.php" "\$c->update('invoice', ['number' => 'F-1'], ['status' => 'draft', 'id' => \$id]);
\$c->update('payment_declaration', ['status' => 'rejected'], ['id' => \$id]);
\$c->insert('invoice_line', ['id' => \$id, 'status' => 'x']);"
out=$(run "$d"); check "a status the criteria read, or another table's, is not a document's written" $? 0 "$out" "OK — 1"

d=$(tree); rm -r "$d/api/tests"
out=$(run "$d"); check "a missing api/tests is refused, not skipped" $? 1 "$out" "found no api/tests"

d=$(tree); out=$(bash "$GATE" --root "$d" --floor 5 2>&1); check "fewer test files read than the floor is refused, not passed" $? 1 "$out" "under the floor of 5"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
