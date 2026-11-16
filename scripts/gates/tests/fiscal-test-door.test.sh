#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d) || exit 1; export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/fiscal-test-door.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: the door made by the test bootstrap, used by a test helper and the scale generator.
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/api/src/DataFixtures/Scale" "$d/api/src/Tenancy" "$d/api/tests/Support" "$d/api/migrations" "$d/infra" "$d/scripts"
  cat > "$d/api/tests/bootstrap.php" <<'PHP'
<?php
$connection->executeStatement(<<<'SQL'
    CREATE OR REPLACE FUNCTION public.fiscal_guard_test_release(open boolean) RETURNS void
        AS $$
    BEGIN
        IF pg_catalog.current_database() !~ '^twes_test' THEN
            RAISE EXCEPTION 'test databases only';
        END IF;
    END
    $$
    SQL);
PHP
  printf '<?php\n$c->executeStatement("SELECT fiscal_guard_test_release(true)");\n' > "$d/api/tests/Support/PastFiscalGuard.php"
  printf '<?php\n$c->executeStatement("SELECT fiscal_guard_test_release(true)");\n' > "$d/api/src/DataFixtures/Scale/ScaleGenerator.php"
  printf '<?php\n$c->executeStatement("SELECT fiscal_release_company(?, ?)");\n' > "$d/api/src/Tenancy/Companies.php"
  echo "$d"
}
run() { bash "$GATE" --root "$1" 2>&1; }
echo "fiscal-test-door gate"

d=$(tree); out=$(run "$d"); check "the door made by the bootstrap and named by the tests and the scale generator passes" $? 0 "$out" "OK"

d=$(tree); printf '<?php\n$this->addSql("SELECT fiscal_guard_test_release(true)");\n' > "$d/api/migrations/Version1.php"
out=$(run "$d"); check "a migration naming the door is refused with its file and line" $? 1 "$out" "api/migrations/Version1.php:2"

d=$(tree); printf '<?php\n$c->executeStatement("SELECT fiscal_guard_test_release(true)");\n' > "$d/api/src/Tenancy/Remove.php"
out=$(run "$d"); check "an application source naming the door is refused" $? 1 "$out" "api/src/Tenancy/Remove.php:2"

d=$(tree); printf 'psql -c "SELECT fiscal_guard_test_release(true)"\n' > "$d/infra/reset.sh"
out=$(run "$d"); check "a script of the images naming the door is refused" $? 1 "$out" "infra/reset.sh:1"

d=$(tree); printf 'x: "SELECT fiscal_guard_test_release(true)"\n' > "$d/scripts/run.yaml"
out=$(run "$d"); check "a script of the tree naming the door is refused" $? 1 "$out" "scripts/run.yaml:1"

d=$(tree); printf '<?php\n$c->executeStatement("CREATE FUNCTION fiscal_guard_test_release(open boolean) RETURNS void AS \\$\\$ BEGIN END \\$\\$");\n' > "$d/api/tests/Support/Another.php"
out=$(run "$d"); check "the door made a second time elsewhere in the tests is refused" $? 1 "$out" "made in api/tests/bootstrap.php alone"

d=$(tree); sed -i '/current_database/,+2d' "$d/api/tests/bootstrap.php"
out=$(run "$d"); check "a bootstrap making the door without refusing other databases is refused" $? 1 "$out" "without refusing"

second_door() { # a second door in the bootstrap, $2 = its refusal of other databases, or nothing
  cat >> "$1/api/tests/bootstrap.php" <<PHP
\$connection->executeStatement(<<<'SQL'
    CREATE OR REPLACE FUNCTION public.fiscal_guard_test_truncate(target regclass) RETURNS void
        AS \$\$
    BEGIN
        $2
        EXECUTE 'TRUNCATE ' || target;
    END
    \$\$
    SQL);
PHP
}

d=$(tree); second_door "$d" "IF pg_catalog.current_database() !~ '^twes_test' THEN RAISE EXCEPTION 'no'; END IF;"
out=$(run "$d"); check "a second door made in the bootstrap with its own refusal passes" $? 0 "$out" "OK"

d=$(tree); second_door "$d" ""
out=$(run "$d"); check "a second door made without its own refusal of other databases is refused" $? 1 "$out" "without refusing"

d=$(tree); printf '<?php\n$c->executeStatement("SELECT fiscal_guard_test_truncate(\x27invoice\x27)");\n' > "$d/api/src/Tenancy/Empty.php"
out=$(run "$d"); check "an application source naming any other door is refused" $? 1 "$out" "api/src/Tenancy/Empty.php:2"

d=$(tree); rm "$d/api/tests/bootstrap.php"
out=$(run "$d"); check "a door made nowhere is refused, not skipped" $? 1 "$out" "found: nowhere"

d=$(tree); rm -r "$d/api/src"
out=$(run "$d"); check "a missing api/src is refused, not skipped" $? 1 "$out" "found no"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
