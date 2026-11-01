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

# A migration the release holds is never edited: a shop that ran it keeps what it did, the edit runs nowhere.
d=$(repo released); migration "$d/api/migrations/Version20261001000000.php" "$(sql 'CREATE TABLE invoice (id UUID NOT NULL, note TEXT, PRIMARY KEY(id))')" "$(sql 'DROP TABLE invoice')"
git -C "$d" add -A
out=$(run "$d"); check "a migration the release holds, edited since: refused" $? 1 "$out" "Version20261001000000.php differs from v2026.10.20.1"

# A statement written over several lines is read whole, and reported where it starts.
d=$(repo released); since "$d" "        \$this->addSql('ALTER TABLE invoice
            DROP note');"
out=$(run "$d"); check "a drop written on the next line of its statement: refused" $? 1 "$out" "Version20261021000000.php:11"
d=$(repo released); since "$d" "        \$this->addSql('ALTER TABLE invoice
            ADD note VARCHAR(255) DEFAULT NULL');"
out=$(run "$d"); check "  an addition over two lines passes" $? 0 "$out" "OK"

# A new rule on a table the release before writes to refuses what it writes.
for statement in 'ALTER TABLE invoice ADD CONSTRAINT chk_total CHECK (total >= 0)' 'ALTER TABLE invoice ADD CHECK (total >= 0)' \
  'ALTER TABLE invoice ADD CONSTRAINT uniq_number UNIQUE (number)' 'CREATE UNIQUE INDEX uniq_number ON invoice (number)'; do
  d=$(repo released); since "$d" "$(sql 'CREATE TABLE note (id UUID NOT NULL, PRIMARY KEY(id))')
$(sql "$statement")"
  out=$(run "$d"); check "refused on a table the release holds: $statement" $? 1 "$out" "Version20261021000000.php:12"
done
d=$(repo released); since "$d" "$(sql 'CREATE TABLE note (id UUID NOT NULL, n INT, PRIMARY KEY(id))')
$(sql 'ALTER TABLE note ADD CONSTRAINT chk_n CHECK (n >= 0)')
$(sql 'CREATE UNIQUE INDEX uniq_n ON note (n)')"
out=$(run "$d"); check "  a rule on a table made since the release passes" $? 0 "$out" "OK"

# The update migrates all or nothing, in one transaction, which CONCURRENTLY cannot join: never, whatever the line says.
d=$(repo released); since "$d" "$(sql 'CREATE INDEX CONCURRENTLY idx_note ON invoice (id)'); // contract: faster"
out=$(run "$d"); check "CONCURRENTLY: refused, even with a reason" $? 1 "$out" "CONCURRENTLY"

# Found by the P0 phase lens: other spellings of a contraction, and statements whose table is not written out.
for statement in 'ALTER TABLE invoice ALTER COLUMN note SET DATA TYPE SMALLINT' 'ALTER TABLE invoice ADD b INT NOT NULL CHECK (b > 0)'; do
  d=$(repo released); since "$d" "$(sql "$statement")"
  out=$(run "$d"); check "refused after a release: $statement" $? 1 "$out" "Version20261021000000.php:11"
done
d=$(repo released); since "$d" "$(sql 'ALTER TABLE invoice ADD CONSTRAINT positive CHECK (total > 0) NOT VALID')"
out=$(run "$d"); check "a CHECK constraint on a held table is a new rule, not a NOT NULL column" $? 1 "$out" "a new rule on invoice"
d=$(repo released); since "$d" "        \$this->addSql(sprintf('ALTER TABLE %s ADD CONSTRAINT u UNIQUE (a)', 'invoice'));"
out=$(run "$d"); check "a new rule whose table is not written out: refused, it cannot be read" $? 1 "$out" "not written out"
d=$(repo released); since "$d" '        foreach (['"'"'invoice'"'"'] as $t) {
            $this->addSql("ALTER TABLE $t ADD CONSTRAINT c CHECK (a > 0)");
        }'
out=$(run "$d"); check "  nor one built from a variable in a loop" $? 1 "$out" "not written out"
d=$(repo released); since "$d" "        \$this->dropOld();"
printf '%s\n' '<?php' 'final class Version20261021000000 extends AbstractMigration' '{' '    public function up(Schema $schema): void' '    {' '        $this->dropOld();' '    }' '' '    private function dropOld(): void' '    {' "        \$this->addSql('ALTER TABLE invoice DROP COLUMN a');" '    }' '' '    public function down(Schema $schema): void' '    {' "        \$this->addSql('ALTER TABLE invoice DROP COLUMN b');" '    }' '}' > "$d/api/migrations/Version20261021000000.php"; git -C "$d" add -A
out=$(run "$d"); check "a contraction in a helper up() calls: refused" $? 1 "$out" "Version20261021000000.php:11"
check "  while down()'s own drops stay the way back" 0 0 "$out" "1 statement(s)"
d=$(repo released); git -C "$d" rm -q api/migrations/Version20261001000000.php; mkdir -p "$d/api/migrations"; since "$d" "$(sql 'CREATE TABLE note (id UUID NOT NULL)')"
out=$(run "$d"); check "a released migration deleted: refused, a shop ran it" $? 1 "$out" "Version20261001000000.php"
# The release a commit is judged against is the newest it descends from: a patch on a release's own branch is not
# judged against what master released since.
d=$(repo released); git -C "$d" checkout -q -b other; echo x > "$d/x"; git -C "$d" add -A; git -C "$d" commit -qm other
git -C "$d" tag -a v2026.10.30.1 -m elsewhere; git -C "$d" checkout -q master
since "$d" "$(sql 'ALTER TABLE invoice ADD note VARCHAR(255) DEFAULT NULL')"
out=$(run "$d"); check "judged against the newest release HEAD descends from, not the newest by name" $? 0 "$out" "since v2026.10.20.1"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
