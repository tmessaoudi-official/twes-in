#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
# None of the machine's own git settings: a developer's commit.gpgsign would sign the fixtures with a real key.
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/additive-migrations.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t
# A migration as the real ones are written: each statement one addSql line, up() before down().
migration() { # file, the lines of up(), the lines of down()
  printf '<?php\n\ndeclare(strict_types=1);\n\nnamespace DoctrineMigrations;\n\nfinal class %s extends AbstractMigration\n{\n    public function up(Schema $schema): void\n    {\n%s\n    }\n\n    public function down(Schema $schema): void\n    {\n%s\n    }\n}\n' \
    "$(basename "$1" .php)" "$2" "${3:-}" > "$1"
}
sql() { printf "        \$this->addSql('%s');" "$1"; }
# A repository with one migration, released when asked.
repo() {
  local d; d=$(mktemp -d); mkdir -p "$d/api/migrations"
  migration "$d/api/migrations/Version20261001000000.php" "$(sql 'CREATE TABLE invoice (id UUID NOT NULL, PRIMARY KEY(id))')" "$(sql 'DROP TABLE invoice')"
  git -C "$d" init -q -b master && git -C "$d" add -A && git -C "$d" commit -qm one
  [[ "${1:-}" == released ]] && git -C "$d" tag -a v2026.10.20.1 -m v2026.10.20.1
  echo "$d"
}
since() { migration "$1/api/migrations/Version20261021000000.php" "$2" "${3:-}"; git -C "$1" add -A; }
run() { bash "$GATE" --root "$1" 2>&1; }
echo "additive-migrations gate"

d=$(repo); since "$d" "$(sql 'ALTER TABLE invoice DROP note')"
out=$(run "$d"); check "before the first release, a migration may still reshape the schema" $? 0 "$out" "no release yet"

d=$(repo released); out=$(run "$d"); check "a release with nothing since passes" $? 0 "$out" "OK"

d=$(repo released); since "$d" "$(sql 'ALTER TABLE invoice ADD note VARCHAR(255) DEFAULT NULL')"
out=$(run "$d"); check "a nullable column added passes" $? 0 "$out" "OK"
d=$(repo released); since "$d" "$(sql "ALTER TABLE invoice ADD kept BOOLEAN DEFAULT false NOT NULL")"
out=$(run "$d"); check "a NOT NULL column with its default passes: the release before inserts without it" $? 0 "$out" "OK"
d=$(repo released); since "$d" "$(sql 'CREATE TABLE note (id UUID NOT NULL, PRIMARY KEY(id))')
$(sql 'CREATE INDEX idx_note ON note (id)')
$(sql 'DROP INDEX idx_old')
$(sql 'ALTER TABLE invoice DROP CONSTRAINT fk_old')
$(sql 'ALTER TABLE invoice ALTER note DROP NOT NULL')"
out=$(run "$d"); check "new tables, indexes, and constraints loosened pass" $? 0 "$out" "OK"

for statement in 'DROP TABLE invoice' 'ALTER TABLE invoice DROP note' 'ALTER TABLE invoice DROP COLUMN note' \
  'ALTER TABLE invoice RENAME COLUMN note TO memo' 'ALTER TABLE invoice RENAME TO bill' 'ALTER INDEX idx_a RENAME TO idx_b' \
  'ALTER TABLE invoice ALTER note TYPE VARCHAR(80)' 'ALTER TABLE invoice ALTER COLUMN note TYPE TEXT' \
  'ALTER TABLE invoice ALTER note SET NOT NULL' 'ALTER TABLE invoice ADD kept BOOLEAN NOT NULL' \
  'ALTER TABLE invoice ALTER kept DROP DEFAULT' 'DROP VIEW invoice_totals' 'drop table invoice'; do
  d=$(repo released); since "$d" "$(sql 'CREATE TABLE note (id UUID NOT NULL, PRIMARY KEY(id))')
$(sql "$statement")"
  out=$(run "$d"); check "refused after a release: $statement" $? 1 "$out" "Version20261021000000.php:12"
done

d=$(repo released); since "$d" "$(sql 'ALTER TABLE invoice ADD note VARCHAR(255) DEFAULT NULL')" "$(sql 'ALTER TABLE invoice DROP note')"
out=$(run "$d"); check "what down() drops is the way back, not a contraction" $? 0 "$out" "OK"
d=$(repo released); since "$d" "$(sql 'ALTER TABLE invoice DROP old_note'); // contract: the release before this one stopped reading old_note"
out=$(run "$d"); check "a contraction whose line says the release before no longer reads it passes" $? 0 "$out" "OK"
d=$(repo released); since "$d" "$(sql 'ALTER TABLE invoice DROP old_note'); // contract:"
out=$(run "$d"); check "  a reason left empty is no reason" $? 1 "$out" "Version20261021000000.php:11"
d=$(repo released); since "$d" "        // Dropping a table is a later release's work.
$(sql 'ALTER TABLE invoice ADD note VARCHAR(255) DEFAULT NULL')"
out=$(run "$d"); check "a comment naming a drop is not one" $? 0 "$out" "OK"

d=$(repo); migration "$d/api/migrations/Version20261001000000.php" "$(sql 'CREATE TABLE invoice (id UUID NOT NULL)')
$(sql 'ALTER TABLE invoice DROP legacy')"
git -C "$d" add -A && git -C "$d" commit -qm reshaped && git -C "$d" tag -a v2026.10.20.1 -m v2026.10.20.1
out=$(run "$d"); check "a migration the release already holds is not read again" $? 0 "$out" "OK"
d=$(repo released); git -C "$d" tag -a v2026.10.21.1 -m v2026.10.21.1; since "$d" "$(sql 'ALTER TABLE invoice DROP note')"
git -C "$d" tag backup/before-something
out=$(run "$d"); check "the newest release tag is the one read, any other tag ignored" $? 1 "$out" "since v2026.10.21.1"
d=$(repo released); since "$d" "$(sql 'ALTER TABLE invoice DROP note')"; git -C "$d" commit -qm two
out=$(run "$d"); check "a committed migration is read as a staged one is" $? 1 "$out" "Version20261021000000.php:11"

d=$(repo released); rm -r "$d/api/migrations"
out=$(run "$d"); check "a missing api/migrations is refused, not skipped" $? 1 "$out" "found no"
d=$(mktemp -d); mkdir -p "$d/api/migrations"
out=$(run "$d"); check "outside a git repository it is refused, not skipped" $? 1 "$out" "not a git"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
