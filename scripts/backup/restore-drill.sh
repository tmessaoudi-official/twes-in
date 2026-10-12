#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The restore drill (docs/SPEC.md § 7, 2026-10-12 00:23; docs/START.md § 13): the production images with the backup
# overlay, a stand-in bucket, and the whole round trip, from the bucket alone into empty volumes. It backs the database
# up, writes a row, notes the moment from the database's own clock, writes a second row, then restores twice:
#   to that moment       only the first row may come back (the target is honoured, not merely "the latest")
#   to the latest        both rows come back (the WAL written after the base backup reached the bucket)
# On the second it starts the API, which must answer healthy, and runs the data check, which must find nothing; restic
# gives back the files volume and the nightly dump, compared byte for byte. Each step's time is printed.
# Runs under the compose project `twes-drill` only, with volumes of its own, which it removes at the end: it never
# reaches a development stack, and refuses to run under any other project name.
# Usage: scripts/backup/restore-drill.sh   (make backup-drill; CI's restore-drill workflow)
set -euo pipefail

PROJECT=twes-drill
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${COMPOSE_PROJECT_NAME:-$PROJECT}" == "$PROJECT" ]] || { echo "restore-drill: refuses COMPOSE_PROJECT_NAME=$COMPOSE_PROJECT_NAME, it runs as $PROJECT only" >&2; exit 2; }
export COMPOSE_PROJECT_NAME=$PROJECT
export COMPOSE_FILE="$root/compose.yaml:$root/compose.prod.yaml:$root/compose.backup.yaml:$root/scripts/backup/drill.compose.yaml"
export COMPOSE_PROFILES=
DRILL_DIR=$(mktemp -d); export DRILL_DIR
VOLUMES=(twes-drill-pg-a twes-drill-pg-t twes-drill-pg-b)

started=$(date +%s); step_started=$started
step() { local now; now=$(date +%s); printf 'restore-drill: %-58s %4ss\n' "$1" "$((now - step_started))"; step_started=$now; }
fail() { echo "restore-drill: FAIL — $*" >&2; exit 1; }
dc() { (cd "$root" && docker compose "$@"); }
# psql against whichever server the compose postgres service runs now.
sql() { dc exec -T postgres psql -U twes -d twes -v ON_ERROR_STOP=1 -tAc "$1"; }

cleanup() {
  local status=$?
  if ((status != 0)); then dc logs --no-color --tail 80 s3 postgres backup api 2>/dev/null || true; fi
  dc --profile restore down --volumes --remove-orphans >/dev/null 2>&1 || true
  docker volume rm "${VOLUMES[@]}" >/dev/null 2>&1 || true
  rm -rf "$DRILL_DIR"
  exit "$status"
}
trap cleanup EXIT

# This instance's own keys, never the development ones (production refuses those), and the bucket's.
random() { head -c "$1" /dev/urandom | base64 | tr -d '\n/+=' | head -c "$2"; }
export APP_SECRET=$(random 48 48) REALTIME_TOKEN_KEY=$(random 48 48) REALTIME_API_KEY=$(random 48 48)
export APP_MFA_KEY=$(head -c 32 /dev/urandom | base64)
export BACKUP_S3_ENDPOINT=s3 BACKUP_S3_PORT=8333 BACKUP_S3_BUCKET=twes-backups BACKUP_S3_REGION=us-east-1 BACKUP_S3_URI_STYLE=path
export BACKUP_S3_KEY=drill$(random 24 12) BACKUP_S3_SECRET=$(random 48 32)
export BACKUP_DATABASE_PASSPHRASE=$(random 48 40) BACKUP_FILES_PASSPHRASE=$(random 48 40)
export DRILL_PG_DATA=twes-drill-pg-a

# A certificate authority that lives as long as the drill, and the stand-in bucket's identity.
dc build -q postgres
mkdir -p "$DRILL_DIR/certs"
docker run --rm -u "$(id -u):$(id -g)" -v "$DRILL_DIR/certs:/c" -w /c --entrypoint sh twes-in-postgres-backup -c '
  openssl req -x509 -newkey rsa:2048 -nodes -keyout ca.key -out ca.crt -days 1 -subj /CN=twes-drill-ca 2>/dev/null
  openssl req -newkey rsa:2048 -nodes -keyout s3.key -out s3.csr -subj /CN=s3 2>/dev/null
  printf "subjectAltName=DNS:s3\n" > ext
  openssl x509 -req -in s3.csr -CA ca.crt -CAkey ca.key -CAcreateserial -out s3.crt -days 1 -extfile ext 2>/dev/null
  chmod 644 ./*'
printf '{"identities":[{"name":"backup","credentials":[{"accessKey":"%s","secretKey":"%s"}],"actions":["Admin","Read","Write","List","Tagging"]}]}\n' \
  "$BACKUP_S3_KEY" "$BACKUP_S3_SECRET" > "$DRILL_DIR/s3.json"
step "images built, keys and certificate made"

dc up -d s3
for _ in $(seq 1 60); do
  dc exec -T s3 sh -c "echo 's3.bucket.create -name $BACKUP_S3_BUCKET' | weed shell" 2>/dev/null | grep -q "created bucket" && break
  sleep 2
done
dc exec -T s3 sh -c "echo 's3.bucket.list' | weed shell" 2>/dev/null | grep -q "$BACKUP_S3_BUCKET" || fail "the stand-in bucket was never made"
dc up -d --build --wait postgres gotenberg api backup
step "stack up: production api, archiving database, backup service ready"

dc exec -T backup twes-backup full
step "full base backup"

# What a pilot writes after last night's backup: it must come back from the WAL alone.
sql "CREATE TABLE restore_drill (n int PRIMARY KEY); INSERT INTO restore_drill VALUES (1)" >/dev/null
sleep 2
target=$(sql "SELECT now()")
sleep 2
sql "INSERT INTO restore_drill VALUES (2); SELECT pg_switch_wal()" >/dev/null
dc exec -T backup gosu postgres pgbackrest --stanza=twes check >/dev/null
# The nightly differential over Sunday's full: the latest restore goes through both, then the WAL after them.
dc exec -T backup twes-backup diff
companies_before=$(sql "SELECT count(*) FROM company")
step "rows written either side of $target, WAL archived"

dc exec -T api sh -c 'head -c 65536 /dev/urandom > /app/var/files/restore-drill.bin && sha256sum /app/var/files/restore-drill.bin' > "$DRILL_DIR/files.sha256"
dc exec -T backup twes-backup copy
# What the weekly check runs: both repositories read back whole.
dc exec -T backup twes-backup verify
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
rows_in() {
  DRILL_PG_DATA=$1 dc up -d --wait postgres >/dev/null
  sql "SELECT coalesce(string_agg(n::text, ',' ORDER BY n), '') FROM restore_drill"
}

restore_into twes-drill-pg-t --type=time "--target=$target" --target-action=promote
# Read with archiving off: promoted, it starts a timeline of its own, which must never reach the bucket the next restore
# reads, since a restore follows the newest timeline it finds there.
DRILL_PG_DATA=twes-drill-pg-t dc run -d --no-deps --name twes-drill-pitr postgres postgres -c archive_mode=off >/dev/null
for _ in $(seq 1 60); do docker exec twes-drill-pitr pg_isready -q -U twes -d twes 2>/dev/null && break; sleep 1; done
got=$(docker exec twes-drill-pitr psql -U twes -d twes -v ON_ERROR_STOP=1 -tAc "SELECT coalesce(string_agg(n::text, ',' ORDER BY n), '') FROM restore_drill")
docker rm -f twes-drill-pitr >/dev/null
[[ "$got" == "1" ]] || fail "restored to $target, the table holds [$got], wanted [1]"
step "restored to the moment between the rows: [1]"

restore_into twes-drill-pg-b --type=default
got=$(rows_in twes-drill-pg-b)
[[ "$got" == "1,2" ]] || fail "restored to the latest, the table holds [$got], wanted [1,2]"
[[ "$(sql "SELECT count(*) FROM company")" == "$companies_before" ]] || fail "the restored database lost companies"
step "restored to the latest: [1,2]"

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
dc exec -T backup restic restore latest --host twes --include /var/lib/twes-backup/dump --target /tmp/back >/dev/null
dc exec -T backup sh -c 'pg_restore --list /tmp/back/var/lib/twes-backup/dump/twes.dump | grep -q "TABLE DATA public restore_drill"' \
  || fail "the nightly dump does not hold the database"
step "files and nightly dump restored from restic, identical"

printf 'restore-drill: OK — restored from the bucket alone in %ss in all\n' "$(($(date +%s) - started))"
