#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The backup command's steps a shop relies on (infra/postgres/backup/twes-backup): its folder made for the database's
# user, the off-site copy that is off until switched on, and the copy taken before an update, which names what a
# restore would go back to. Its tools are stand-ins that write down how they were called.
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
SCRIPT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/infra/postgres/backup/twes-backup
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

# Each tool appends its own line to calls; pgbackrest answers `info` with INFO_JSON, restic fails when told to.
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
echo "restic $*" >> "$TMPDIR/calls"
[[ " $* " == *" cat config "* ]] && exit "${RESTIC_MISSING:-0}"
exit "${RESTIC_STATUS:-0}"
EOF
for tool in pg_isready pg_dump mv curl supercronic; do printf '#!/usr/bin/env bash\necho "%s $*" >> "$TMPDIR/calls"\n' "$tool" > "$TMPDIR/bin/$tool"; done
cat > "$TMPDIR/bin/install" <<'EOF'
#!/usr/bin/env bash
echo "install $*" >> "$TMPDIR/calls"
EOF
chmod +x "$TMPDIR/bin/"*
O=$TMPDIR/outcome
run() { rm -f "$TMPDIR/calls"; env PATH="$TMPDIR/bin:$PATH" MONITOR_URL= OUTCOME_DIRECTORY="$O" BACKUP_LOCK="$TMPDIR/lock" "$@" bash "$SCRIPT" "$STEP" 2>&1; }
# A step's last success, so many hours ago.
done_ago() { mkdir -p "$O"; echo "$(( $(date +%s) - $2 * 3600 ))" > "$O/$1.ok"; }
calls() { [[ -f "$TMPDIR/calls" ]] && tr '\n' '|' < "$TMPDIR/calls"; }
echo "backup steps"

out=$(STEP=init run PGBACKREST_REPO1_TYPE=posix PGBACKREST_REPO1_PATH=/backup/pgbackrest)
check "a shop's folder is made for the database's user before the stanza" $? 0 "$(calls)" "install -d -o postgres -g postgres -m 0750 /backup/pgbackrest|pgbackrest --stanza=twes stanza-create"
out=$(STEP=init run PGBACKREST_REPO1_TYPE=s3 PGBACKREST_REPO1_PATH=/pgbackrest)
[[ "$(calls)" != *"install "* ]]; check "a bucket needs no folder made" $? 0 "$out" ""

out=$(STEP=offsite run OFFSITE_REPOSITORY=)
check "the off-site copy is off until switched on, and says so" $? 0 "$out" "off-site copy off"
[[ -z "$(calls)" ]]; check "and touches nothing" $? 0 "$(calls)" ""
out=$(STEP=offsite run OFFSITE_REPOSITORY= MONITOR_URL=https://monitor.test MONITOR_HEARTBEAT_TOKEN=beat)
[[ "$(calls)" != *"curl"* ]]; check "nor tells a server's monitor of a step it never runs" $? 0 "$(calls)" ""
out=$(STEP=offsite run OFFSITE_REPOSITORY=s3:https://bucket.test:443/b/offsite MONITOR_URL=https://monitor.test MONITOR_HEARTBEAT_TOKEN=beat)
check "switched on, it has its own heartbeat" $? 0 "$(calls)" "Sauvegardes_Hors_site/external"

out=$(STEP=offsite run OFFSITE_REPOSITORY=s3:https://bucket.test:443/b/offsite)
check "switched on, it copies the whole local folder into the off-site repository" $? 0 "$(calls)" "restic -o s3.bucket-lookup=dns -r s3:https://bucket.test:443/b/offsite backup --tag offsite --host twes /backup|"
check "then keeps fourteen days there" 0 0 "$(calls)" "-r s3:https://bucket.test:443/b/offsite forget --host twes --keep-within 14d --prune"
out=$(STEP=offsite run OFFSITE_REPOSITORY=s3:https://bucket.test:443/b/offsite RESTIC_MISSING=1)
check "making the off-site repository the first time" $? 0 "$(calls)" "-r s3:https://bucket.test:443/b/offsite init"
out=$(STEP=offsite run OFFSITE_REPOSITORY=s3:https://bucket.test:443/b/offsite BACKUP_S3_URI_STYLE=path)
check "addressed by path when the bucket is" $? 0 "$(calls)" "restic -o s3.bucket-lookup=path -r s3:https://bucket.test:443/b/offsite backup"
out=$(STEP=offsite run OFFSITE_REPOSITORY=s3:https://bucket.test:443/b/offsite RESTIC_STATUS=1)
check "a night without the internet fails that night's copy, loudly" $? 1 "$out" ""

label='20261020-130501F_20261020-130512I'
info='[{"backup":[{"label":"20261019-010001F","type":"full"},{"label":"'$label'","type":"incr"}],"name":"twes"}]'
out=$(STEP=before-update run INFO_JSON="$info")
check "before an update, an incremental base backup marked as such" $? 0 "$(calls)" "pgbackrest --stanza=twes --type=incr --annotation=reason=before-update backup"
check "then the dump and the files, tagged" 0 0 "$(calls)" "restic -o s3.bucket-lookup=dns backup --tag before-update --host twes"
check "and it names the backup a restore goes back to" 0 0 "$out" "before-update: $label"
out=$(STEP=before-update run PGBR_STATUS=5)
check "a base backup that fails stops the update before anything else" $? 5 "$out" ""
[[ "$(calls)" != *"restic"* ]]; check "and nothing is copied after it" $? 0 "$(calls)" ""
out=$(STEP=before-update run INFO_JSON='[]')
check "a backup it cannot name is a failure, never an update without a way back" $? 1 "$out" "names no backup"

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

rm -rf "$O"; done_ago full 30; done_ago copy 1; done_ago verify 30
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

rm -rf "$O"; done_ago full 30; done_ago diff 2; done_ago copy 2; done_ago verify 30
out=$(STEP=due run OFFSITE_REPOSITORY=s3:https://bucket.test:443/b/offsite)
check "the off-site copy switched on is due once a day" $? 0 "$(calls)" "-r s3:https://bucket.test:443/b/offsite backup"

rm -rf "$O"; out=$(STEP=due run PGBR_STATUS=2)
check "a base backup that fails fails the run" $? 1 "$out" "failed"
check "  and the copy still runs" 0 0 "$(calls)" "pg_dump"

out=$(STEP=run run BACKUP_SCHEDULE=due)
check "a shop's schedule runs due every half hour" $? 0 "$(calls)" "supercronic -passthrough-logs /etc/twes-backup/crontab.due"
out=$(STEP=run run)
check "a server keeps the nightly schedule" $? 0 "$(calls)" "supercronic -passthrough-logs /etc/twes-backup/crontab|"
out=$(STEP=run run BACKUP_SCHEDULE=weekly)
check "a schedule of another name is refused" $? 1 "$out" "BACKUP_SCHEDULE"

echo "backup steps: $pass passed, $fail failed"
((fail == 0))
