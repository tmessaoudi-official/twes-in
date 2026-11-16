#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The backup command's steps a shop relies on (infra/postgres/backup/twes-backup): its folder made for the database's
# user, the off-site copy that is off until switched on, and the copy taken before an update, which names what a
# restore would go back to. Its tools are stand-ins that write down how they were called.
set -uo pipefail
TMPDIR=$(mktemp -d) || exit 1; export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
SCRIPT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/infra/postgres/backup/twes-backup
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

# Each tool appends its own line to calls; pgbackrest answers `info` with INFO_JSON, restic fails when told to and,
# with RESTIC_SHOW_KEY, writes down the bucket key it ran under. A backup asked for JSON names its snapshot (5a1, 5a2…
# by the backups made so far); `ls` lists what SNAP_HOLDS_<n> says the n-th listing holds, else SNAP_HOLDS; pg_restore
# reads the dump's file table as holding DUMP_KEYS.
mkdir -p "$TMPDIR/bin"
cat > "$TMPDIR/bin/gosu" <<'EOF'
#!/usr/bin/env bash
shift
exec "$@"
EOF
cat > "$TMPDIR/bin/pgbackrest" <<'EOF'
#!/usr/bin/env bash
echo "pgbackrest $*" >> "$TMPDIR/calls"
[[ " $* " == *" info "* ]] && printf '%s\n' "${INFO_JSON:-[]}"
exit "${PGBR_STATUS:-0}"
EOF
cat > "$TMPDIR/bin/restic" <<'EOF'
#!/usr/bin/env bash
echo "restic $*${RESTIC_SHOW_KEY:+ key=$AWS_ACCESS_KEY_ID secret=${AWS_SECRET_ACCESS_KEY:-}}" >> "$TMPDIR/calls"
# A repository found missing reads back once it has been made.
[[ " $* " == *" cat config "* ]] && { grep -qE ' init( |$)' "$TMPDIR/calls" && exit 0; exit "${RESTIC_MISSING:-0}"; }
if [[ " $* " == *" backup "* && " $* " == *" --json "* ]]; then
  echo '{"message_type":"status","percent_done":1}'
  echo "{\"message_type\":\"summary\",\"files_new\":2,\"snapshot_id\":\"5a$(grep -c ' backup ' "$TMPDIR/calls")\"}"
fi
if [[ " $* " == *" ls "* ]]; then
  held=SNAP_HOLDS_$(grep -c ' ls ' "$TMPDIR/calls"); held=${!held-${SNAP_HOLDS:-}}
  echo "snapshot 5a of [/var/lib/twes-backup/dump /files] at 2026-11-15 01:30:00:"
  echo /files; echo /files/companies
  for key in $held; do echo "/files/$key"; done
fi
exit "${RESTIC_STATUS:-0}"
EOF
cat > "$TMPDIR/bin/pg_restore" <<'EOF'
#!/usr/bin/env bash
echo "pg_restore $*" >> "$TMPDIR/calls"
printf 'SET statement_timeout = 0;\n\nCOPY public.file (id, company_id, storage_key, original_name, mime) FROM stdin;\n'
for key in ${DUMP_KEYS:-}; do printf '01a1\t01b2\t%s\tfacture 1.pdf\tapplication/pdf\n' "$key"; done
printf '\\.\n\n'
EOF
for tool in pg_isready pg_dump curl supercronic; do printf '#!/usr/bin/env bash\necho "%s $*" >> "$TMPDIR/calls"\n' "$tool" > "$TMPDIR/bin/$tool"; done
# mv stands in for the dump's move, whose file the stand-in pg_dump never wrote; an outcome, written beside its file and
# renamed over it, is moved for real.
cat > "$TMPDIR/bin/mv" <<'EOF'
#!/usr/bin/env bash
[[ -e "${@: -2:1}" ]] && { command -p mv "$@"; exit; }
echo "mv $*" >> "$TMPDIR/calls"
EOF
cat > "$TMPDIR/bin/install" <<'EOF'
#!/usr/bin/env bash
echo "install $*" >> "$TMPDIR/calls"
EOF
chmod +x "$TMPDIR/bin/"*
# The month's restore check: pg_ctl starts a server that leaves its pid file behind, as a real one does; psql says the
# live cluster's size (DB_SIZE) and that the restored server has left recovery; df says the room left (AVAIL bytes);
# pg_amcheck answers AMCHECK_STATUS.
cat > "$TMPDIR/bin/pg_ctl" <<'EOF'
#!/usr/bin/env bash
echo "pg_ctl $*" >> "$TMPDIR/calls"
[[ " $* " != *" start "* ]] || { mkdir -p "$2"; echo 4242 > "$2/postmaster.pid"; }
[[ " $* " != *" stop "* ]] || rm -f "$2/postmaster.pid"
EOF
cat > "$TMPDIR/bin/psql" <<'EOF'
#!/usr/bin/env bash
echo "psql $*" >> "$TMPDIR/calls"
[[ "$*" == *pg_database_size* ]] && echo "${DB_SIZE:-1000}"
[[ "$*" == *pg_is_in_recovery* ]] && echo f
exit 0
EOF
cat > "$TMPDIR/bin/pg_amcheck" <<'EOF'
#!/usr/bin/env bash
echo "pg_amcheck $*" >> "$TMPDIR/calls"
exit "${AMCHECK_STATUS:-0}"
EOF
cat > "$TMPDIR/bin/df" <<'EOF'
#!/usr/bin/env bash
echo "df $*" >> "$TMPDIR/calls"
printf '    Avail\n%s\n' "${AVAIL:-100000000}"
EOF
chmod +x "$TMPDIR/bin/"*
O=$TMPDIR/outcome
C=$TMPDIR/check
run() { rm -f "$TMPDIR/calls"; env PATH="$TMPDIR/bin:$PATH" MONITOR_URL= OUTCOME_DIRECTORY="$O" BACKUP_LOCK="$TMPDIR/lock" CHECK_DIR="$C" "$@" bash "$SCRIPT" "$STEP" 2>&1; }
# A step's last success, so many hours ago.
done_ago() { mkdir -p "$O"; echo "$(( $(date +%s) - $2 * 3600 ))" > "$O/$1.ok"; }
calls() { [[ -f "$TMPDIR/calls" ]] && tr '\n' '|' < "$TMPDIR/calls"; }
echo "backup steps"

out=$(STEP=init run PGBACKREST_REPO1_TYPE=posix PGBACKREST_REPO1_PATH=/backup/pgbackrest)
check "a shop's folder is made for the database's user before the stanza" $? 0 "$(calls)" "install -d -o postgres -g postgres -m 0750 /backup/pgbackrest|pgbackrest --stanza=twes stanza-create"
# The folder a shop's values name by its identity: an empty one in its place (the drive unplugged) is refused, so the
# database's archive waits in pg_wal for the drive rather than starting a stray repository.
F=$TMPDIR/folder; mkdir -p "$F"
out=$(STEP=init run PGBACKREST_REPO1_TYPE=posix PGBACKREST_REPO1_PATH=/backup/pgbackrest BACKUP_FOLDER="$F" BACKUP_FOLDER_ID=f0f0)
check "a folder without the values' identity: refused" $? 1 "$out" "not the backups' folder"
[[ "$(calls)" != *stanza-create* && "$(calls)" != *"restic"* ]]; check "  no stanza, no repository made in it" $? 0 "$(calls)" ""
echo f0f1 > "$F/twes-in-backups.id"
out=$(STEP=init run PGBACKREST_REPO1_TYPE=posix PGBACKREST_REPO1_PATH=/backup/pgbackrest BACKUP_FOLDER="$F" BACKUP_FOLDER_ID=f0f0)
check "  another folder's identity: refused" $? 1 "$out" "not the backups' folder"
echo f0f0 > "$F/twes-in-backups.id"
out=$(STEP=init run PGBACKREST_REPO1_TYPE=posix PGBACKREST_REPO1_PATH=/backup/pgbackrest BACKUP_FOLDER="$F" BACKUP_FOLDER_ID=f0f0)
check "  its own: made ready" $? 0 "$(calls)" "pgbackrest --stanza=twes stanza-create"
out=$(STEP=init run PGBACKREST_REPO1_TYPE=s3 PGBACKREST_REPO1_PATH=/pgbackrest)
[[ "$(calls)" != *"install "* ]]; check "a bucket needs no folder made" $? 0 "$out" ""
# restic says 10 when a repository does not exist; anything else (a bucket out of reach, a key refused, a wrong
# passphrase) is no missing repository, and one made then would start a second history beside the first.
out=$(STEP=init run RESTIC_MISSING=10)
check "the files repository not found: made" $? 0 "$(calls)" "restic -o s3.bucket-lookup=dns init"
out=$(STEP=init run RESTIC_MISSING=1)
check "the files repository unreadable: refused, saying why" $? 1 "$out" "could not be read (restic exited 1)"
[[ "$(calls)" != *" init"* ]]; check "  and no repository made" $? 0 "$(calls)" ""
out=$(STEP=init run RESTIC_MISSING=12)
check "  nor for a wrong passphrase" $? 12 "$(calls)" ""
[[ "$(calls)" != *" init"* ]]; check "  which makes none either" $? 0 "$(calls)" ""

out=$(STEP=offsite run OFFSITE_REPOSITORY=)
check "the off-site copy is off until switched on, and says so" $? 0 "$out" "off-site copy off"
[[ -z "$(calls)" ]]; check "and touches nothing" $? 0 "$(calls)" ""
out=$(STEP=offsite run OFFSITE_REPOSITORY= MONITOR_URL=https://monitor.test MONITOR_HEARTBEAT_TOKEN=beat)
[[ "$(calls)" != *"curl"* ]]; check "nor tells a server's monitor of a step it never runs" $? 0 "$(calls)" ""
out=$(STEP=offsite run OFFSITE_REPOSITORY=s3:https://bucket.test:443/b/offsite MONITOR_URL=https://monitor.test MONITOR_HEARTBEAT_TOKEN=beat)
check "switched on, it has its own heartbeat" $? 0 "$(calls)" "Sauvegardes_Hors_site/external"

rm -rf "$O"; out=$(STEP=offsite run OFFSITE_REPOSITORY=s3:https://bucket.test:443/b/offsite)
check "switched on, it copies the whole local folder into the off-site repository" $? 0 "$(calls)" "restic -o s3.bucket-lookup=dns -r s3:https://bucket.test:443/b/offsite backup --tag offsite --host twes /backup|"
# The computer's key only adds: one that could prune could also empty the bucket, so a computer taken over would
# take its off-site copies with it. The developer prunes, with a key the computer never holds.
[[ "$(calls)" != *" forget "* && "$(calls)" != *"prune"* ]]; check "  and never prunes it, whatever the time since" $? 0 "$(calls)" ""
out=$(STEP=offsite run OFFSITE_REPOSITORY=s3:https://bucket.test:443/b/offsite RESTIC_MISSING=10)
check "making the off-site repository the first time" $? 0 "$(calls)" "-r s3:https://bucket.test:443/b/offsite init"
out=$(STEP=offsite run OFFSITE_REPOSITORY=s3:https://bucket.test:443/b/offsite RESTIC_MISSING=1)
check "an off-site bucket that cannot be read: the copy fails, saying why" $? 1 "$out" "could not be read (restic exited 1)"
[[ "$(calls)" != *" init"* && "$(calls)" != *" backup "* ]]; check "  and nothing is made or copied there" $? 0 "$(calls)" ""

# The prune the developer runs from a machine of their own (docs/RUNBOOK.md), with the prune key given for that run.
out=$(STEP=prune-offsite run OFFSITE_REPOSITORY=s3:https://bucket.test:443/b/offsite)
check "the off-site prune without its own key: refused" $? 1 "$out" "OFFSITE_PRUNE_KEY and OFFSITE_PRUNE_SECRET: the bucket's prune key"
[[ -z "$(calls)" ]]; check "  touching nothing" $? 0 "$(calls)" ""
out=$(STEP=prune-offsite run OFFSITE_REPOSITORY=s3:https://bucket.test:443/b/offsite AWS_ACCESS_KEY_ID=pc OFFSITE_PRUNE_KEY=dev)
check "  nor with its key's name alone" $? 1 "$out" "the bucket's prune key"
out=$(STEP=prune-offsite run OFFSITE_REPOSITORY=s3:https://bucket.test:443/b/offsite AWS_ACCESS_KEY_ID=pc OFFSITE_PRUNE_KEY=pc OFFSITE_PRUNE_SECRET=s)
check "  the computer's own key given as the prune key: refused" $? 1 "$out" "the computer's own"
out=$(STEP=prune-offsite run OFFSITE_REPOSITORY=)
check "  no off-site repository named: refused" $? 1 "$out" "OFFSITE_REPOSITORY"
out=$(STEP=prune-offsite run OFFSITE_REPOSITORY=s3:https://bucket.test:443/b/offsite AWS_ACCESS_KEY_ID=pc OFFSITE_PRUNE_KEY=dev OFFSITE_PRUNE_SECRET=s RESTIC_SHOW_KEY=1)
check "with its key: fourteen days kept, the rest pruned" $? 0 "$(calls)" "restic -o s3.bucket-lookup=dns -r s3:https://bucket.test:443/b/offsite forget --host twes --keep-within 14d --prune"
check "  under the prune key, never the computer's" 0 0 "$(calls)" "--prune key=dev secret=s"
check "  the stale locks the computer's copies could not remove gone first" 0 0 "$(calls)" "-r s3:https://bucket.test:443/b/offsite unlock key=dev secret=s|restic -o s3.bucket-lookup=dns -r s3:https://bucket.test:443/b/offsite forget"
out=$(STEP=prune-offsite run OFFSITE_REPOSITORY=s3:https://bucket.test:443/b/offsite OFFSITE_PRUNE_KEY=dev OFFSITE_PRUNE_SECRET=s RESTIC_STATUS=1)
check "  a prune that fails says so" $? 1 "$out" ""
[[ "$out" != *"the rest pruned"* ]]; check "  and claims nothing" $? 0 "$out" ""
out=$(STEP=offsite run OFFSITE_REPOSITORY=s3:https://bucket.test:443/b/offsite BACKUP_S3_URI_STYLE=path)
check "addressed by path when the bucket is" $? 0 "$(calls)" "restic -o s3.bucket-lookup=path -r s3:https://bucket.test:443/b/offsite backup"
out=$(STEP=offsite run OFFSITE_REPOSITORY=s3:https://bucket.test:443/b/offsite RESTIC_STATUS=1)
check "a night without the internet fails that night's copy, loudly" $? 1 "$out" ""

label='20261020-130501F_20261020-130512I'
info='[{"backup":[{"label":"20261019-010001F","type":"full"},{"label":"'$label'","type":"incr"}],"name":"twes"}]'
out=$(STEP=before-update run INFO_JSON="$info")
check "before an update, an incremental base backup marked as such" $? 0 "$(calls)" "pgbackrest --stanza=twes --type=incr --annotation=reason=before-update backup"
check "then the dump and the files, tagged" 0 0 "$(calls)" "restic -o s3.bucket-lookup=dns backup --json --tag before-update --host twes"
check "and it names the backup a restore goes back to" 0 0 "$out" "before-update: $label"
out=$(STEP=before-update run PGBR_STATUS=5)
check "a base backup that fails stops the update before anything else" $? 5 "$out" ""
[[ "$(calls)" != *"restic"* ]]; check "and nothing is copied after it" $? 0 "$(calls)" ""
out=$(STEP=before-update run INFO_JSON='[]')
check "a backup it cannot name is a failure, never an update without a way back" $? 1 "$out" "names no backup"

# The dump and the files from one point: a file the dump names, removed before restic read the files (a removal ends
# its row, then its bytes), would come back named and missing. Every file the dump names is looked for in the snapshot.
count() { calls | grep -o -- "$1" | wc -l; }
rm -rf "$O"; out=$(STEP=copy run DUMP_KEYS="companies/c1/f1 companies/c1/f2" SNAP_HOLDS="companies/c1/f1 companies/c1/f2 companies/c2/f9")
check "the copy: the dump and the files in one snapshot" $? 0 "$(calls)" "restic -o s3.bucket-lookup=dns backup --json --tag nightly --host twes /var/lib/twes-backup/dump /files|"
check "  every file the dump names looked for in that snapshot" 0 0 "$(calls)" "pg_restore --data-only --table=file --file=- /var/lib/twes-backup/dump/twes.dump|restic -o s3.bucket-lookup=dns ls --recursive 5a1 /files|"
[[ "$(count ' backup --json')" == 1 ]]; check "  found, taken once" $? 0 "$(calls)" ""
check "  then what is past 14 days pruned" 0 0 "$(calls)" "forget --host twes --keep-within 14d --prune"
rm -rf "$O"; out=$(STEP=copy run DUMP_KEYS="companies/c1/f1 companies/c1/f2" SNAP_HOLDS_1="companies/c1/f1" SNAP_HOLDS="companies/c1/f1 companies/c1/f2")
check "a file the dump names gone from the snapshot: the dump and the files taken again" $? 0 "$out" "1 file the dump names was removed before the files were read: copied again"
[[ "$(count ' backup --json')" == 2 && "$(count 'pg_dump ')" == 2 ]]; check "  the dump with them" $? 0 "$(calls)" ""
check "  and the second found whole" 0 0 "$out" "dump and files copied (nightly)"
rm -rf "$O"; out=$(STEP=copy run DUMP_KEYS="companies/c1/f1 companies/c1/f2" SNAP_HOLDS="companies/c1/f1")
check "never found whole: the copy fails, saying why" $? 1 "$out" "1 file the dump names is still missing from the files after 3 copies"
[[ "$(count ' backup --json')" == 3 ]]; check "  after three tries" $? 0 "$(calls)" ""
[[ "$out" != *"dump and files copied"* ]]; check "  and claims nothing" $? 0 "$out" ""
rm -rf "$O"; out=$(STEP=copy run RESTIC_STATUS=1)
check "a backup that fails fails the copy" $? 1 "$out" ""
[[ "$(count ' backup --json')" == 1 ]]; check "  without trying again" $? 0 "$(calls)" ""

# Each step writes down how it ended, monitor or not: what due reads, and what a shop's twes tick alerts on.
rm -rf "$O"; out=$(STEP=full run)
check "a step that worked is written down as such" $? 0 "$(cat "$O/full" 2>&1)" " ok"
[[ -s "$O/full.ok" ]]; check "  with the moment of its last success" $? 0 "" ""
out=$(STEP=diff run PGBR_STATUS=2)
check "a step that failed is written down with why" 0 0 "$(cat "$O/diff" 2>&1)" "failed diff exited 2"
[[ ! -e "$O/diff.ok" ]]; check "  and no success is claimed for it" $? 0 "" ""

# due: what a computer switched off at night runs once it is on, each step once its last success is older than its period.
rm -rf "$O"; before=$(date +%s); out=$(STEP=due run)
check "nothing done yet: a full backup first" $? 0 "$(calls)" "pgbackrest --stanza=twes --type=full backup"
ran='' word=''; [[ -f "$O/due" ]] && read -r ran word < "$O/due"; [[ "$ran" =~ ^[0-9]+$ && "$ran" -ge "$before" && "$word" == ran ]]
check "  and the schedule writes down that it ran, which twes tick reads to know it is alive" $? 0 "$(cat "$O/due" 2>&1)" ""
check "  then the copy" 0 0 "$(calls)" "pg_dump"
check "  then both repositories read back" 0 0 "$(calls)" "pgbackrest --stanza=twes verify"
[[ "$(calls)" != *"--type=diff"* && "$(calls)" != *"offsite"* ]]; check "  and no differential, nor the off-site copy while it is off" $? 0 "$(calls)" ""

rm -rf "$O"; done_ago full 30; done_ago copy 1; done_ago verify 30; done_ago restore-check 1
out=$(STEP=due run)
check "a day since the full backup: a differential" $? 0 "$(calls)" "--type=diff backup"
[[ "$(calls)" != *"--type=full"* && "$(calls)" != *"pg_dump"* && "$(calls)" != *"pgbackrest --stanza=twes verify"* ]]
check "  and nothing whose last success is recent" $? 0 "$(calls)" ""

rm -rf "$O"; done_ago full 30; done_ago diff 2; done_ago copy 2; done_ago verify 30
out=$(STEP=due run); [[ "$(calls)" != *"pgbackrest --stanza=twes --type"* ]]
check "a differential two hours old: no base backup" $? 0 "$(calls)" ""

rm -rf "$O"; done_ago full 200; done_ago diff 2; done_ago copy 2; done_ago verify 30
out=$(STEP=due run); check "a full backup older than a week: a full one, whatever the differentials" $? 0 "$(calls)" "--type=full backup"

rm -rf "$O"; done_ago full 30; done_ago diff 2; done_ago copy 30; done_ago verify 200
out=$(STEP=due run); check "a week since the last read-back: read back" $? 0 "$(calls)" "pgbackrest --stanza=twes verify"

# A clock that ran ahead wrote its successes on days still to come; set right again, those ages read below zero, and
# every step would wait for the clock to catch up, weeks without a backup. A success after now is no success to rely on.
rm -rf "$O"; done_ago full -720; done_ago diff -720; done_ago copy -720; done_ago verify -720
out=$(STEP=due run); check "successes dated after now (a clock that ran ahead): a full backup" $? 0 "$(calls)" "--type=full backup"
check "  the copy" 0 0 "$(calls)" "pg_dump"
check "  and the read-back" 0 0 "$(calls)" "pgbackrest --stanza=twes verify"

rm -rf "$O"; done_ago full 30; done_ago diff 2; done_ago copy 2; done_ago verify 30
out=$(STEP=due run OFFSITE_REPOSITORY=s3:https://bucket.test:443/b/offsite)
check "the off-site copy switched on, never made: made" $? 0 "$(calls)" "-r s3:https://bucket.test:443/b/offsite backup"
[[ "$(calls)" != *" forget "* ]]; check "  and never pruned from here" $? 0 "$(calls)" ""

# A lost computer loses what the bucket lacks: the folder goes off site every hour.
rm -rf "$O"; done_ago full 30; done_ago diff 2; done_ago copy 2; done_ago verify 30; done_ago offsite 1
out=$(STEP=due run OFFSITE_REPOSITORY=s3:https://bucket.test:443/b/offsite)
check "an hour since the off-site copy: copied again" $? 0 "$(calls)" "-r s3:https://bucket.test:443/b/offsite backup"
rm -rf "$O"; done_ago full 30; done_ago diff 2; done_ago copy 2; done_ago verify 30; done_ago offsite 0
out=$(STEP=due run OFFSITE_REPOSITORY=s3:https://bucket.test:443/b/offsite); [[ "$(calls)" != *"offsite backup"* ]]
check "an off-site copy just made: not again" $? 0 "$(calls)" ""
rm -rf "$O"; done_ago full 30; done_ago diff 2; done_ago copy 2; done_ago verify 30; done_ago offsite 2
out=$(STEP=due run OFFSITE_REPOSITORY=s3:https://bucket.test:443/b/offsite OFFSITE_EVERY=86400); [[ "$(calls)" != *"offsite backup"* ]]
check "the period is a parameter: once a day, two hours is not due" $? 0 "$(calls)" ""

rm -rf "$O"; out=$(STEP=due run PGBR_STATUS=2)
check "a base backup that fails fails the run" $? 1 "$out" "failed"
check "  and the copy still runs" 0 0 "$(calls)" "pg_dump"

# The month's restore check: the latest backup restored into a folder of its own, never the server's, started with
# archiving off (promoted, it begins a timeline of its own, which must never reach the repository the next restore
# reads), every table and index checked and every row read, then stopped and removed, whatever happened.
rm -rf "$O"; out=$(STEP=restore-check run)
check "the restore check restores the latest into its own folder, archiving off" $? 0 "$(calls)" "pgbackrest --stanza=twes --pg1-path=$C --archive-mode=off --spool-path=/tmp restore"
check "  its folder and its socket's made for the database's user" 0 0 "$(calls)" "install -d -o postgres -g postgres -m 0700 $C $C.socket"
check "  starts it with archiving off, on a socket of its own" 0 0 "$(calls)" "pg_ctl -D $C -l $C.log -w -t 3600 -o -c archive_mode=off -c listen_addresses= -c port=5499 -c unix_socket_directories=$C.socket start"
check "  checks every table and index once it has left recovery" 0 0 "$(calls)" "pg_is_in_recovery()|pg_amcheck -h $C.socket -p 5499 -U twes --all --heapallindexed --install-missing"
check "  reads every row" 0 0 "$(calls)" "pg_dump -h $C.socket -p 5499 -U twes -d twes --format=custom --no-sync --file=/dev/null"
check "  then stops it" 0 0 "$(calls)" "pg_ctl -D $C -m fast -w stop"
[[ ! -e "$C" && ! -e "$C.log" ]]; check "  and removes it" $? 0 "$(ls -a "$TMPDIR")" ""
[[ "$(calls)" != *"/var/lib/postgresql/data"* ]]; check "  never naming the server's own data" $? 0 "$(calls)" ""
check "  written down as done" 0 0 "$(cat "$O/restore-check" 2>&1)" " ok"
[[ -s "$O/restore-check.ok" && -s "$O/restore-check-tried.ok" ]]; check "  with its success and its try" $? 0 "$(ls "$O")" ""
rm -rf "$O"; out=$(STEP=restore-check run AMCHECK_STATUS=2)
check "a check that finds damage fails, saying so" $? 2 "$(cat "$O/restore-check" 2>&1)" "failed restore-check exited 2"
check "  its server stopped all the same" 0 0 "$(calls)" "pg_ctl -D $C -m fast -w stop"
[[ ! -e "$C" ]]; check "  and its folder removed" $? 0 "" ""
[[ ! -e "$O/restore-check.ok" && -s "$O/restore-check-tried.ok" ]]; check "  tried, never claimed" $? 0 "$(ls "$O")" ""
out=$(STEP=restore-check run DB_SIZE=1000000 AVAIL=1000)
check "too little room for the check: refused, saying how much it needs" $? 1 "$out" "needs 1250000 bytes"
[[ "$(calls)" != *" restore"* ]]; check "  before restoring anything" $? 0 "$(calls)" ""
mkdir -p "$C"; echo 4242 > "$C/postmaster.pid"
out=$(STEP=restore-check run)
check "a killed check's server and folder: stopped and removed first" $? 0 "$(calls)" "pg_ctl -D $C -m fast -w stop|"
[[ "$(calls)" == *"-w stop|"*"--pg1-path=$C"*" restore"* ]]; check "  before the restore" $? 0 "$(calls)" ""

rm -rf "$O"; done_ago full 30; done_ago diff 2; done_ago copy 2; done_ago verify 30
out=$(STEP=due run); check "a shop never checked: a restore check" $? 0 "$(calls)" "--pg1-path=$C"
rm -rf "$O"; done_ago full 30; done_ago diff 2; done_ago copy 2; done_ago verify 30; done_ago restore-check 240
out=$(STEP=due run); [[ "$(calls)" != *"--pg1-path"* ]]; check "checked ten days ago: not again" $? 0 "$(calls)" ""
rm -rf "$O"; done_ago full 30; done_ago diff 2; done_ago copy 2; done_ago verify 30; done_ago restore-check 744
out=$(STEP=due run); check "a month since: checked" $? 0 "$(calls)" "--pg1-path=$C"
rm -rf "$O"; done_ago full 30; done_ago diff 2; done_ago copy 2; done_ago verify 30; done_ago restore-check 744
out=$(STEP=due run AMCHECK_STATUS=2)
out=$(STEP=due run); [[ "$(calls)" != *"--pg1-path"* ]]; check "  a check that failed waits a day, rather than every half hour" $? 0 "$(calls)" ""
done_ago restore-check-tried 25
out=$(STEP=due run); check "  and runs again the next day" $? 0 "$(calls)" "--pg1-path=$C"

out=$(STEP=run run BACKUP_SCHEDULE=due)
check "a shop's schedule runs due every half hour" $? 0 "$(calls)" "supercronic -passthrough-logs /etc/twes-backup/crontab.due"
out=$(STEP=run run)
check "a server keeps the nightly schedule" $? 0 "$(calls)" "supercronic -passthrough-logs /etc/twes-backup/crontab|"
out=$(STEP=run run BACKUP_SCHEDULE=weekly)
check "a schedule of another name is refused" $? 1 "$out" "BACKUP_SCHEDULE"

echo "backup steps: $pass passed, $fail failed"
((fail == 0))
