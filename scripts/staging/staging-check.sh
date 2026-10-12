#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The staging check (docs/RUNBOOK.md): the whole road from a commit to a staging that runs it, on this machine, with a
# local registry standing in for GHCR:
#   publish     scripts/release/publish.sh builds the three images from a clean clone and pushes them, :master moved
#   follow      scripts/deploy/staging-follow.sh, in a staging checkout of its own, reads :master and deploys it
#   by digest   every container runs the image its digest names, and nothing was built under the check's project
#   through     the edge answers health and a sign-in over HTTPS
#   again       a second follow finds nothing to do
#   redeploy    with the deployment down and the three images gone from this machine, deploy.sh --staging with the
#               release written down under releases/ pulls them back and starts them: what a rollback does
# What it checks is the working tree as it is, committed or not: a snapshot commit made through a temporary index (the
# real index is never touched) is what the bare origin, the publishing clone and the staging clone hold.
# Runs under the compose project `twes-staging-check` only, with a registry container of its own bound to 127.0.0.1,
# and removes its containers, volumes, images and directories at the end.
# Usage: scripts/staging/staging-check.sh   (make staging-check; CI's staging-check workflow)
set -euo pipefail

PROJECT=twes-staging-check
REGISTRY_PORT=5099
PREFIX=localhost:$REGISTRY_PORT/twes-check
REGISTRY_NAME=$PROJECT-registry
REGISTRY_IMAGE=registry:3.0.0
HOST=staging-check.twes.test
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${COMPOSE_PROJECT_NAME:-$PROJECT}" == "$PROJECT" ]] || { echo "staging-check: refuses COMPOSE_PROJECT_NAME=$COMPOSE_PROJECT_NAME, it runs as $PROJECT only" >&2; exit 2; }
export COMPOSE_PROJECT_NAME=$PROJECT COMPOSE_PROFILES=
WORK=$(mktemp -d)
export DRILL_DIR=$WORK DRILL_PG_DATA=$PROJECT-pg
staging=$WORK/staging values=$WORK/etc/deploy.env

started=$(date +%s); step_started=$started
step() { local now; now=$(date +%s); printf 'staging-check: %-62s %4ss\n' "$1" "$((now - step_started))"; step_started=$now; }
fail() { echo "staging-check: FAIL — $*" >&2; exit 1; }
release_value() { awk -v k="$1" 'index($0, k "=") == 1 { v = substr($0, length(k) + 2) } END { print v }' "$2"; }
# The staging deployment's compose, as deploy.sh --staging runs it with the check's layers.
export DEPLOY_EXTRA_FILES="$staging/scripts/backup/drill.compose.yaml:$staging/scripts/deploy/check.compose.yaml"
dc() {
  (cd "$staging" && docker compose --env-file "$values" -f compose.yaml -f compose.prod.yaml -f compose.backup.yaml \
    -f compose.deploy.yaml -f compose.release.yaml -f scripts/backup/drill.compose.yaml -f scripts/deploy/check.compose.yaml "$@")
}

images_of() { # the three image references a release file names
  local part; for part in API WEB DATABASE; do release_value "TWES_${part}_IMAGE" "$1"; done
}
forget_images() { # every name this machine holds the published images under, so the next start has to pull
  local ref revision=$1
  for ref in $(images_of "$WORK/release.env" 2>/dev/null); do docker image rm -f "$ref" >/dev/null 2>&1 || true; done
  for part in api web database; do
    docker image rm -f "twes-release-$part:$revision" "$PREFIX/twes-in-$part:sha-$revision" "$PREFIX/twes-in-$part:master" >/dev/null 2>&1 || true
  done
}
cleanup() {
  local status=$?
  if ((status != 0)) && [[ -f "$values" ]]; then dc logs --no-color --tail 60 edge web api backup 2>/dev/null || true; fi
  [[ -f "$values" ]] && { dc --profile restore down --volumes --remove-orphans >/dev/null 2>&1 || true; }
  docker volume rm "$DRILL_PG_DATA" >/dev/null 2>&1 || true
  docker rm -f "$REGISTRY_NAME" >/dev/null 2>&1 || true
  [[ -n "${snapshot:-}" ]] && forget_images "$snapshot"
  rm -rf "$WORK"
  exit "$status"
}
trap cleanup EXIT

# The working tree as one commit on top of HEAD, made in a copy of the index.
git_dir=$(git -C "$root" rev-parse --absolute-git-dir)
cp "$git_dir/index" "$WORK/index"
GIT_INDEX_FILE=$WORK/index git -C "$root" add -A
tree=$(GIT_INDEX_FILE=$WORK/index git -C "$root" write-tree)
snapshot=$(git -C "$root" -c user.name=staging-check -c user.email=staging-check@twes.test commit-tree "$tree" -p HEAD -m "staging-check snapshot")
git init -q --bare "$WORK/origin.git"
git -C "$root" push -q "$WORK/origin.git" "$snapshot:refs/heads/master"
git clone -q "$WORK/origin.git" "$WORK/publish"
git clone -q "$WORK/origin.git" "$staging"
step "snapshot $snapshot cloned to an origin, a publisher and a staging"

(exec 3<>"/dev/tcp/127.0.0.1/$REGISTRY_PORT") 2>/dev/null && fail "127.0.0.1:$REGISTRY_PORT is taken: the check's registry needs it"
docker run -d --name "$REGISTRY_NAME" --log-opt max-size=10m --log-opt max-file=5 -p "127.0.0.1:$REGISTRY_PORT:5000" "$REGISTRY_IMAGE" >/dev/null
for _ in $(seq 1 30); do (exec 3<>"/dev/tcp/127.0.0.1/$REGISTRY_PORT") 2>/dev/null && break; sleep 1; done
bash "$WORK/publish/scripts/release/publish.sh" "$PREFIX" "$WORK/release.env" > "$WORK/publish.log" 2>&1 || { cat "$WORK/publish.log" >&2; fail "publish.sh failed"; }
grep -q "moved :master to $snapshot" "$WORK/publish.log" || fail ":master was not moved to the snapshot: $(tail -3 "$WORK/publish.log")"
[[ "$(release_value TWES_REVISION "$WORK/release.env")" == "$snapshot" ]] || fail "the release file names another commit"
step "three images built from the clean clone and pushed, :master moved"

# Staging's values, as an operator starts them, the facts filled in for a stack nobody outside reaches.
mkdir -p "$WORK/etc"
bash "$staging/scripts/deploy/new-instance.sh" --staging "$values" >/dev/null
fact() { sed -i "s|^$1=.*|$1=$2|" "$values"; grep -q "^$1=$2\$" "$values" || fail "could not set $1"; }
fact TWES_HOST "$HOST"
fact EDGE_TLS internal
fact MAILER_DSN smtp://mailpit:1025
fact MAILER_SENDER "no-reply@$HOST"
fact BACKUP_S3_ENDPOINT s3
fact BACKUP_S3_BUCKET twes-backups
fact BACKUP_S3_REGION us-east-1
fact BACKUP_S3_KEY "check$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')"
fact BACKUP_S3_SECRET "$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')"
printf 'BACKUP_S3_PORT=8333\nBACKUP_S3_URI_STYLE=path\n' >> "$values"
fact MONITOR_URL "https://status.$HOST"
fact SENTRY_DSN "https://check@errors.$HOST/1"
BACKUP_S3_KEY=$(release_value BACKUP_S3_KEY "$values") BACKUP_S3_SECRET=$(release_value BACKUP_S3_SECRET "$values") BACKUP_S3_BUCKET=twes-backups
export TWES_API_IMAGE TWES_WEB_IMAGE TWES_DATABASE_IMAGE
TWES_API_IMAGE=$(release_value TWES_API_IMAGE "$WORK/release.env")
TWES_WEB_IMAGE=$(release_value TWES_WEB_IMAGE "$WORK/release.env")
TWES_DATABASE_IMAGE=$(release_value TWES_DATABASE_IMAGE "$WORK/release.env")
# shellcheck source=scripts/backup/stand-in-bucket.sh
source "$root/scripts/backup/stand-in-bucket.sh"
stand_in_bucket_keys "$TWES_DATABASE_IMAGE"
stand_in_bucket_up
step "staging's values written by new-instance.sh --staging, bucket up"

bash "$staging/scripts/deploy/staging-follow.sh" "$PREFIX" "$values" > "$WORK/follow.log" 2>&1 || { cat "$WORK/follow.log" >&2; fail "staging-follow.sh failed"; }
grep -q "deploying $snapshot" "$WORK/follow.log" || fail "staging-follow did not deploy the snapshot: $(tail -3 "$WORK/follow.log")"
[[ "$(tail -1 "$WORK/etc/releases/history")" == *" deployed $snapshot staging" ]] || fail "the deployment was not written down"
step "staging-follow deployed :master"

# Every container that runs a twes-in image runs the one its digest names; none was built under this project.
by_digest() {
  local service image cid
  for service in api:$TWES_API_IMAGE worker:$TWES_API_IMAGE web:$TWES_WEB_IMAGE postgres:$TWES_DATABASE_IMAGE backup:$TWES_DATABASE_IMAGE; do
    image=${service#*:}; service=${service%%:*}
    cid=$(dc ps -q "$service"); [[ -n "$cid" ]] || fail "$service is not running"
    [[ "$(docker inspect --format '{{.Image}}' "$cid")" == "$(docker image inspect --format '{{.Id}}' "$image")" ]] \
      || fail "$service runs another image than $image"
  done
  [[ -z "$(docker image ls -q --filter "reference=$PROJECT-*")" ]] || fail "an image was built under $PROJECT: $(docker image ls --filter "reference=$PROJECT-*")"
}
by_digest
step "every container runs its digest, nothing built here"

dc exec -T edge cat /data/caddy/pki/authorities/local/root.crt > "$WORK/edge-root.crt"
ask() { dc exec -T gotenberg curl -sS --cacert /tmp/edge-root.crt --connect-to "$HOST:443:edge:443" --max-time 10 "$@"; }
through_the_edge() {
  dc exec -T gotenberg sh -c 'cat > /tmp/edge-root.crt' < "$WORK/edge-root.crt"
  for _ in $(seq 1 30); do ask -o /dev/null "https://$HOST/api/health" 2>/dev/null && break; sleep 1; done
  ask "https://$HOST/api/health" | grep -q '"status":"ok"' || fail "/api/health is not ok through the edge"
}
through_the_edge
password=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '%s\n' "$password" | dc exec -T api bin/console app:seed --no-interaction --operator-email="operator@$HOST" --operator-password-stdin --without-company >/dev/null \
  || fail "app:seed --without-company could not make the operator"
csrf=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')
sign_in() {
  ask -c /tmp/jar -o /dev/null -w '%{http_code}' -H "Origin: https://$HOST" -H "csrf-token: $csrf" -H 'Content-Type: application/json' \
    --data "{\"email\":\"operator@$HOST\",\"password\":\"$password\"}" "https://$HOST/api/auth/login"
}
[[ "$(sign_in)" == 200 ]] || fail "signing in through the edge failed"
step "healthy and signed in through the edge"

bash "$staging/scripts/deploy/staging-follow.sh" "$PREFIX" "$values" > "$WORK/follow2.log" 2>&1 || { cat "$WORK/follow2.log" >&2; fail "the second follow failed"; }
grep -q "already deployed here" "$WORK/follow2.log" || fail "a second follow did not leave staging as it was: $(cat "$WORK/follow2.log")"
step "a second follow finds nothing to do"

# A rollback: the services running the published images removed, the images gone from this machine, the release written
# down started again. The stand-in bucket stays: its data lives in its container.
dc rm -sf api worker web postgres backup >/dev/null 2>&1
forget_images "$snapshot"
for ref in $(images_of "$WORK/release.env"); do ! docker image inspect "$ref" >/dev/null 2>&1 || fail "$ref is still on this machine"; done
bash "$staging/scripts/deploy/deploy.sh" --staging "$values" "$WORK/etc/releases/$snapshot.env" > "$WORK/redeploy.log" 2>&1 \
  || { cat "$WORK/redeploy.log" >&2; fail "redeploying the recorded release failed"; }
by_digest
through_the_edge
[[ "$(sign_in)" == 200 ]] || fail "signing in after the redeploy failed: the database volume was kept, the operator should be there"
step "images removed, the recorded release pulled back and running"

printf 'staging-check: OK — published, followed and redeployed by digest in %ss in all\n' "$(($(date +%s) - started))"
