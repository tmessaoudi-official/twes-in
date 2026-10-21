#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/database-clock.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: a step reading invitations on the application's clock.
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/api/src/Tenancy"
  printf '<?php\n$open = $this->connection->fetchOne(%s, [%s => $this->clock->now()]);\n$at = Clock::get()->now();\n' "'SELECT 1 FROM invitation WHERE expires_at >= :now'" "'now'" > "$d/api/src/Tenancy/Step.php"
  echo "$d"
}
query() { printf '<?php\n$found = $this->connection->fetchOne(%s);\n' "$2" > "$1"; }
run() { bash "$GATE" --root "$1" 2>&1; }
echo "database-clock gate"

d=$(tree); out=$(run "$d"); check "a query handed the application's clock passes, and PHP's own now() is no query" $? 0 "$out" "OK"

d=$(tree); query "$d/api/src/Tenancy/Members.php" "'SELECT 1 FROM invitation WHERE expires_at > NOW()'"
out=$(run "$d"); check "a query deciding on NOW() is refused with its file and line" $? 1 "$out" "Members.php:2"

d=$(tree); query "$d/api/src/Tenancy/Late.php" "'SELECT 1 FROM invoice WHERE due_date < CURRENT_DATE'"
out=$(run "$d"); check "CURRENT_DATE is the database's clock too" $? 1 "$out" "Late.php:2"

d=$(tree); query "$d/api/src/Tenancy/Age.php" "'SELECT now() - created_at FROM invoice'"
out=$(run "$d"); check "a lower-case now() is refused" $? 1 "$out" "Age.php:2"

d=$(tree); printf '<?php\n$sql = <<<SQL\n    -- database-clock: Messenger stamps it with the wall clock\n    SELECT 1 WHERE available_at <= now()\n    SQL;\n' > "$d/api/src/Tenancy/Queue.php"
out=$(run "$d"); check "a SQL comment above saying why passes" $? 0 "$out" "OK"

d=$(tree); printf '<?php\n$found = $this->connection->fetchOne(%s); // database-clock: the reading is the database time\n' "'SELECT now()'" > "$d/api/src/Tenancy/Queue.php"
out=$(run "$d"); check "a reason on the line passes" $? 0 "$out" "OK"

d=$(tree); printf '<?php\n$found = $this->connection->fetchOne(%s); // database-clock:\n' "'SELECT now()'" > "$d/api/src/Tenancy/Queue.php"
out=$(run "$d"); check "a reason left empty is no reason" $? 1 "$out" "Queue.php:2"

d=$(tree); printf '<?php\n/** Never compare with NOW() in a query. */\n' > "$d/api/src/Tenancy/Doc.php"
out=$(run "$d"); check "a clock named in a comment is not a query" $? 0 "$out" "OK"

d=$(tree); printf '<?php\n$moment = $this->clock->now();\n$n = self::now();\n$x = $p::now();\n' > "$d/api/src/Tenancy/Calls.php"
out=$(run "$d"); check "a method named now() is not the database's" $? 0 "$out" "OK"

d=$(tree); rm -r "$d/api/src"
out=$(run "$d"); check "a missing api/src is refused, not skipped" $? 1 "$out" "found no api/src"

d=$(tree); rm "$d/api/src/Tenancy/Step.php"
out=$(run "$d"); check "an api/src holding no PHP is refused, not passed" $? 1 "$out" "found no PHP"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
