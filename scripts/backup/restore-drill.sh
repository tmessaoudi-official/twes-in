#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The restore drill (docs/SPEC.md § 7, 2026-10-12 00:23; docs/START.md § 13): the production images with the backup
# overlay, a stand-in bucket, and the whole round trip, from the bucket alone into empty volumes. It backs the database
# up, writes a row, notes the moment from the database's own clock, writes a second row, then restores twice:
#   to that moment       only the first row may come back (the target is honoured, not merely "the latest")
#   to the latest        both rows come back (the WAL written after the base backup reached the bucket)
# On the second it starts the API, which must answer healthy, and runs the data check, which must find nothing; restic
# gives back the files volume and the nightly dump, compared byte for byte. Each step's time is printed.
# `local` drills a shop's backups instead (compose.backup-local.yaml, docs/START.md § 16): the repositories in a folder,
# copied off site to the stand-in bucket (compose.backup-offsite.yaml), the copy an update takes first restored by its
# label, and the latest restored after the folder itself is lost, from the off-site copy alone.
# Runs under the compose project `twes-drill` only, with volumes of its own, which it removes at the end: it never
# reaches a development stack, and refuses to run under any other project name.
# Usage: scripts/backup/restore-drill.sh [bucket|local]   (make backup-drill [DRILL=local]; CI's restore-drill workflow)
set -euo pipefail

PROJECT=twes-drill
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${COMPOSE_PROJECT_NAME:-$PROJECT}" == "$PROJECT" ]] || { echo "restore-drill: refuses COMPOSE_PROJECT_NAME=$COMPOSE_PROJECT_NAME, it runs as $PROJECT only" >&2; exit 2; }
export COMPOSE_PROJECT_NAME=$PROJECT
mode=${1:-bucket}
case "$mode" in
  bucket) destination="$root/compose.backup-bucket.yaml" ;;
  local) destination="$root/compose.backup-local.yaml:$root/compose.backup-offsite.yaml" ;;
  *) echo "Usage: restore-drill.sh [bucket|local]" >&2; exit 2 ;;
esac
export COMPOSE_FILE="$root/compose.yaml:$root/compose.prod.yaml:$root/compose.backup.yaml:$destination:$root/scripts/backup/drill.compose.yaml"
export COMPOSE_PROFILES=
DRILL_DIR=$(mktemp -d); export DRILL_DIR
VOLUMES=(twes-drill-pg-a twes-drill-pg-t twes-drill-pg-b twes-drill-pg-u)
# A shop's folder as a host path, as on a second disk: the services write it as their own users, so it is emptied
# through one of them before the run's directory goes.
export BACKUP_DIRECTORY=$DRILL_DIR/backup
mkdir -p "$BACKUP_DIRECTORY"

started=$(date +%s); step_started=$started
step() { local now; now=$(date +%s); printf 'restore-drill: %-58s %4ss\n' "$1" "$((now - step_started))"; step_started=$now; }
fail() { echo "restore-drill: FAIL — $*" >&2; exit 1; }
dc() { (cd "$root" && docker compose "$@"); }
# psql against whichever server the compose postgres service runs now.
sql() { dc exec -T postgres psql -U twes -d twes -v ON_ERROR_STOP=1 -tAc "$1"; }

# The shop's folder emptied by root in the backup image, which wrote it; the stack's containers are gone by then.
empty_folder() {
  docker run --rm -v "$BACKUP_DIRECTORY:/backup" --entrypoint sh twes-in-postgres-backup -c 'rm -rf /backup/* /backup/.[!.]*'
}

cleanup() {
  local status=$?
  if ((status != 0)); then dc logs --no-color --tail 80 s3 postgres backup api 2>/dev/null || true; fi
  dc --profile restore down --volumes --remove-orphans >/dev/null 2>&1 || true
  docker volume rm "${VOLUMES[@]}" >/dev/null 2>&1 || true
  if [[ "$mode" == local ]]; then empty_folder >/dev/null 2>&1 || true; fi
  rm -rf "$DRILL_DIR"
  exit "$status"
}
trap cleanup EXIT

# This instance's own keys, never the development ones (production refuses those), and the bucket's.
random() { head -c "$1" /dev/urandom | base64 | tr -d '\n/+=' | head -c "$2"; }
export APP_SECRET=$(random 48 48) REALTIME_TOKEN_KEY=$(random 48 48) REALTIME_API_KEY=$(random 48 48)
export APP_MFA_KEY=$(head -c 32 /dev/urandom | base64) POSTGRES_PASSWORD=$(random 48 40) POSTGRES_APP_PASSWORD=$(random 48 40)
# Development's mail catcher, as the secret a relay's address would be.
export MAILER_DSN=smtp://mailpit:1025
export BACKUP_S3_ENDPOINT=s3 BACKUP_S3_PORT=8333 BACKUP_S3_BUCKET=twes-backups BACKUP_S3_REGION=us-east-1 BACKUP_S3_URI_STYLE=path
export BACKUP_S3_KEY=drill$(random 24 12) BACKUP_S3_SECRET=$(random 48 32)
export BACKUP_DATABASE_PASSPHRASE=$(random 48 40) BACKUP_FILES_PASSPHRASE=$(random 48 40)
export DRILL_PG_DATA=twes-drill-pg-a

# shellcheck source=scripts/backup/stand-in-bucket.sh
source "$root/scripts/backup/stand-in-bucket.sh"
stand_in_bucket_keys
step "images built, keys and certificate made"

stand_in_bucket_up
dc up -d --build --wait postgres gotenberg api backup
step "stack up: production api, archiving database, backup service ready"

if [[ "$mode" == local ]]; then
  # A shop's schedule (twes-backup due) on a new install: nothing done yet, so it takes the full backup, the copy and the
  # read-back by itself, each under the lock and written down in the outcome volume, and the next run finds nothing due.
  dc exec -T backup twes-backup due
  for done_step in full copy verify; do
    dc exec -T backup test -s "/var/lib/twes-backup/outcome/$done_step.ok" || fail "due wrote down no success for $done_step"
  done
  again=$(dc exec -T backup twes-backup due 2>&1)
  [[ -z "$again" ]] || fail "due ran again what it had just done: $again"
  step "due on a new shop: full backup, copy, read-back, each written down"
else
  dc exec -T backup twes-backup full
  step "full base backup"
fi

# What a pilot writes after last night's backup: it must come back from the WAL alone.
sql "CREATE TABLE restore_drill (n int PRIMARY KEY); INSERT INTO restore_drill VALUES (1)" >/dev/null
sleep 2
target=$(sql "SELECT now()")
sleep 2
sql "INSERT INTO restore_drill VALUES (2); SELECT pg_switch_wal()" >/dev/null
dc exec -T backup twes-secrets gosu postgres pgbackrest --stanza=twes check >/dev/null
# The nightly differential over Sunday's full: the latest restore goes through both, then the WAL after them.
dc exec -T backup twes-backup diff
companies_before=$(sql "SELECT count(*) FROM company")
step "rows written either side of $target, WAL archived"

if [[ "$mode" == local ]]; then
  # What an update takes first (scripts/onprem/twes): the label it names is what a restore goes back to.
  label=$(dc exec -T backup twes-backup before-update | sed -n 's/.*before-update: //p')
  [[ "$label" =~ ^[0-9]{8}-[0-9]{6}F(_[0-9]{8}-[0-9]{6}[DI])?$ ]] || fail "the copy before an update named no backup: [$label]"
  sql "INSERT INTO restore_drill VALUES (3)" >/dev/null
  step "the copy before an update made: $label; a third row written after it"
fi

dc exec -T api sh -c 'head -c 65536 /dev/urandom > /app/var/files/restore-drill.bin && sha256sum /app/var/files/restore-drill.bin' > "$DRILL_DIR/files.sha256"
dc exec -T backup twes-backup copy
# What the weekly check runs: both repositories read back whole.
dc exec -T backup twes-backup verify
if [[ "$mode" == local ]]; then
  sql "SELECT pg_switch_wal()" >/dev/null
  dc exec -T backup twes-secrets gosu postgres pgbackrest --stanza=twes check >/dev/null
  dc exec -T backup twes-backup offsite
fi
# The file changes after the copy, so only a restore can bring the written bytes back.
dc exec -T api sh -c 'head -c 65536 /dev/urandom > /app/var/files/restore-drill.bin'
step "nightly dump and files copied, both repositories verified"

dc stop api backup postgres >/dev/null
# The overlay's restore service, as docs/START.md § 13 runs it, into a database volume nothing has written to, as on a
# new machine: the server's data is never deleted here.
restore_into() {
  local volume=$1; shift
  DRILL_PG_DATA=$volume dc run --rm --no-deps -T restore gosu postgres pgbackrest --stanza=twes --spool-path=/tmp "$@" restore >/dev/null
}
# A restored server answers pg_isready, and so its healthcheck, while it still replays WAL towards its target (hot
# standby): what it holds is read once it has promoted, never at its first answer. Says how long that took past the answer.
promoted() { # a command that runs psql's arguments against the restored server
  local waited=0
  until [[ "$("$@" -tAc 'SELECT pg_is_in_recovery()')" == f ]]; do
    ((waited < 120)) || fail "the restored server never left recovery"
    sleep 1; waited=$((waited + 1))
  done
  echo "restore-drill: promoted ${waited}s after it first answered" >&2
}
rows_in() {
  DRILL_PG_DATA=$1 dc up -d --wait postgres >/dev/null
  promoted dc exec -T postgres psql -U twes -d twes
  sql "SELECT coalesce(string_agg(n::text, ',' ORDER BY n), '') FROM restore_drill"
}

restore_into twes-drill-pg-t --type=time "--target=$target" --target-action=promote
# Read with archiving off: promoted, it starts a timeline of its own, which must never reach the bucket the next restore
# reads, since a restore follows the newest timeline it finds there.
DRILL_PG_DATA=twes-drill-pg-t dc run -d --no-deps --name twes-drill-pitr postgres postgres -c archive_mode=off >/dev/null
for _ in $(seq 1 60); do docker exec twes-drill-pitr pg_isready -q -U twes -d twes 2>/dev/null && break; sleep 1; done
promoted docker exec twes-drill-pitr psql -U twes -d twes
if ! got=$(docker exec twes-drill-pitr psql -U twes -d twes -v ON_ERROR_STOP=1 -tAc "SELECT coalesce(string_agg(n::text, ',' ORDER BY n), '') FROM restore_drill"); then
  docker logs twes-drill-pitr 2>&1 | tail -n 40
  docker rm -f twes-drill-pitr >/dev/null
  fail "the server restored to $target could not be read"
fi
docker rm -f twes-drill-pitr >/dev/null
[[ "$got" == "1" ]] || fail "restored to $target, the table holds [$got], wanted [1]"
step "restored to the moment between the rows: [1]"

latest=1,2
if [[ "$mode" == local ]]; then
  latest=1,2,3
  restore_into twes-drill-pg-u "--set=$label" --type=immediate --target-action=promote
  DRILL_PG_DATA=twes-drill-pg-u dc run -d --no-deps --name twes-drill-update postgres postgres -c archive_mode=off >/dev/null
  for _ in $(seq 1 60); do docker exec twes-drill-update pg_isready -q -U twes -d twes 2>/dev/null && break; sleep 1; done
  got=$(docker exec twes-drill-update psql -U twes -d twes -v ON_ERROR_STOP=1 -tAc "SELECT coalesce(string_agg(n::text, ',' ORDER BY n), '') FROM restore_drill")
  docker rm -f twes-drill-update >/dev/null
  [[ "$got" == "1,2" ]] || fail "restored to the copy before an update, the table holds [$got], wanted [1,2]"
  step "restored to the copy taken before an update: [1,2]"

  # The machine's folder is lost: it comes back from the off-site copy alone, then the database from it.
  empty_folder
  dc run --rm --no-deps -T restore sh -c 'restic -o s3.bucket-lookup=path -r "$OFFSITE_REPOSITORY" restore latest --host twes --include /backup --target /' >/dev/null
  step "the lost folder brought back from the off-site copy"
fi
restore_into twes-drill-pg-b --type=default
got=$(rows_in twes-drill-pg-b)
[[ "$got" == "$latest" ]] || fail "restored to the latest, the table holds [$got], wanted [$latest]"
[[ "$(sql "SELECT count(*) FROM company")" == "$companies_before" ]] || fail "the restored database lost companies"
step "restored to the latest: [$latest]"

# The files as docs/START.md § 13 brings them back, over the volume the api reads, before the api starts on it.
DRILL_PG_DATA=twes-drill-pg-b dc run --rm --no-deps -T restore restic restore latest --host twes --include /files --target / >/dev/null
DRILL_PG_DATA=twes-drill-pg-b dc up -d --wait api >/dev/null
dc exec -T api php -r 'exit(str_contains((string) file_get_contents("http://localhost/api/health"), "\"status\":\"ok\"") ? 0 : 1);' \
  || fail "the API on the restored database is not healthy"
dc exec -T api php bin/console app:data:check --no-interaction || fail "the data check found something in the restored database"
step "API healthy and data check clean on the restored database"

dc exec -T api sha256sum /app/var/files/restore-drill.bin > "$DRILL_DIR/back.sha256" || fail "restic gave back no files volume"
[[ "$(cut -d' ' -f1 "$DRILL_DIR/files.sha256")" == "$(cut -d' ' -f1 "$DRILL_DIR/back.sha256")" ]] || fail "the file restic gave back differs from the one copied"
DRILL_PG_DATA=twes-drill-pg-b dc up -d --wait backup >/dev/null
dc exec -T backup twes-secrets restic restore latest --host twes --include /var/lib/twes-backup/dump --target /tmp/back >/dev/null
dc exec -T backup sh -c 'pg_restore --list /tmp/back/var/lib/twes-backup/dump/twes.dump | grep -q "TABLE DATA public restore_drill"' \
  || fail "the nightly dump does not hold the database"
step "files and nightly dump restored from restic, identical"

if [[ "$mode" == local ]]; then whence="the off-site copy alone"; else whence="the bucket alone"; fi
printf 'restore-drill: OK (%s) — restored from %s in %ss in all\n' "$mode" "$whence" "$(($(date +%s) - started))"
