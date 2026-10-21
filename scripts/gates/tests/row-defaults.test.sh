#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/row-defaults.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: a repository reading raw rows through the throwing reader.
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/api/src/Files"
  printf '<?php\nfinal class Store\n{\n    public function bytes(): int\n    {\n        return Rows::int($this->connection->fetchOne(%s));\n    }\n}\n' "'SELECT 1'" > "$d/api/src/Files/Store.php"
  echo "$d"
}
reader() { printf '<?php\n$rows = $this->connection->fetchAllAssociative($sql);\n%s\n' "$2" > "$1"; }
run() { bash "$GATE" --root "$1" --floor 1 2>&1; }
echo "row-defaults gate"

d=$(tree); out=$(run "$d"); check "a raw read through the throwing reader passes" $? 0 "$out" "OK"

d=$(tree); reader "$d/api/src/Files/Bytes.php" 'return is_numeric($bytes) ? (int) $bytes : 0;'
out=$(run "$d"); check "a count read as 0 when it is not one is refused with its file and line" $? 1 "$out" "Bytes.php:3"

d=$(tree); reader "$d/api/src/Files/Names.php" "\$name = \\is_string(\$row['name']) ? \$row['name'] : '';"
out=$(run "$d"); check "a name read as empty when it is not text is refused" $? 1 "$out" "Names.php:3"

d=$(tree); reader "$d/api/src/Files/Sums.php" "\$quantity = self::decimal(\$row['quantity'] ?? 0);"
out=$(run "$d"); check "a missing column read as 0 is refused" $? 1 "$out" "Sums.php:3"

d=$(tree); reader "$d/api/src/Files/Found.php" 'return array_filter(\is_array($found) ? $found : [], $isInvoice);'
out=$(run "$d"); check "a result read as nothing when it is not a list is refused" $? 1 "$out" "Found.php:3"

d=$(tree); printf '<?php\n$code = \\is_string($body["code"] ?? null) ? $body["code"] : %s;\n' "''" > "$d/api/src/Files/Controller.php"
out=$(run "$d"); check "a request body's default, in a file reading no rows, is left to its own checks" $? 0 "$out" "OK"

d=$(tree); reader "$d/api/src/Files/Why.php" "\$label = \\is_string(\$row['label']) ? \$row['label'] : ''; // row-default: a label the schema lets be empty reads as none"
out=$(run "$d"); check "a default whose line says why passes" $? 0 "$out" "OK"

d=$(tree); reader "$d/api/src/Files/Why.php" "// row-default: a label the schema lets be empty reads as none
\$label = \\is_string(\$row['label']) ? \$row['label'] : '';"
out=$(run "$d"); check "a default whose line above says why passes" $? 0 "$out" "OK"

d=$(tree); reader "$d/api/src/Files/Why.php" "\$label = \\is_string(\$row['label']) ? \$row['label'] : ''; // row-default:"
out=$(run "$d"); check "a reason left empty is no reason" $? 1 "$out" "Why.php:3"

d=$(tree); reader "$d/api/src/Files/Doc.php" "/** Never write \$row['x'] ?? 0 on a raw row. */"
out=$(run "$d"); check "a default named in a comment is not one" $? 0 "$out" "OK"

d=$(tree); out=$(bash "$GATE" --root "$d" --floor 5 2>&1); check "fewer raw readers than the floor is refused, not passed" $? 1 "$out" "found 1"

d=$(tree); rm -r "$d/api/src"
out=$(run "$d"); check "a missing api/src is refused, not skipped" $? 1 "$out" "found no"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
