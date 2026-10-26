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
#   rotations   APP_MFA_KEY (an enrolled authenticator unreadable under the new key alone, signing in again once
#               app:keys:rotate-mfa resealed it), APP_SECRET (a mail's stop link made before refused, one made after
#               taken), the realtime keys (Centrifugo refuses the old token and API key, takes the new ones), the
#               database password and the application role's (the old one refused, the deployment healthy), each by
#               the runbook's procedure
# What it checks is the working tree as it is, committed or not: a snapshot commit made through a temporary index (the
# real index is never touched) is what the bare origin, the publishing clone and the staging clone hold.
# Runs under the compose project `twes-staging-check` only, with a registry container of its own bound to 127.0.0.1,
# and removes its containers, volumes, images and directories at the end.
# Usage: scripts/staging/staging-check.sh   (make staging-check; CI's staging-check workflow)
set -euo pipefail
# None of the machine's own git settings: a developer's commit.gpgsign would sign the fixtures with a real key.
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1

PROJECT=twes-staging-check
REGISTRY_PORT=5099
PREFIX=localhost:$REGISTRY_PORT/twes-check
REGISTRY_NAME=$PROJECT-registry
REGISTRY_IMAGE=registry:3.1.2
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
  (cd "$staging" && docker compose --env-file "$values" -f compose.yaml -f compose.prod.yaml -f compose.backup.yaml -f compose.backup-bucket.yaml \
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
  if ((status != 0)) && [[ -f "$values" ]]; then dc logs --no-color --tail 60 edge web api migrate backup 2>/dev/null || true; fi
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
  for service in api:$TWES_API_IMAGE worker:$TWES_API_IMAGE demo-worker:$TWES_API_IMAGE web:$TWES_WEB_IMAGE postgres:$TWES_DATABASE_IMAGE backup:$TWES_DATABASE_IMAGE; do
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
ask() { dc exec -T edge curl -sS --cacert /tmp/edge-root.crt --connect-to "$HOST:443:edge:443" --max-time 10 "$@"; }
through_the_edge() {
  dc exec -T edge sh -c 'cat > /tmp/edge-root.crt' < "$WORK/edge-root.crt"
  for _ in $(seq 1 30); do ask -f -o /dev/null "https://$HOST/api/health" 2>/dev/null && break; sleep 1; done
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
dc rm -sf api worker demo-worker web postgres backup >/dev/null 2>&1
forget_images "$snapshot"
for ref in $(images_of "$WORK/release.env"); do ! docker image inspect "$ref" >/dev/null 2>&1 || fail "$ref is still on this machine"; done
bash "$staging/scripts/deploy/deploy.sh" --staging "$values" "$WORK/etc/releases/$snapshot.env" > "$WORK/redeploy.log" 2>&1 \
  || { cat "$WORK/redeploy.log" >&2; fail "redeploying the recorded release failed"; }
by_digest
through_the_edge
[[ "$(sign_in)" == 200 ]] || fail "signing in after the redeploy failed: the database volume was kept, the operator should be there"
step "images removed, the recorded release pulled back and running"

# The rotations (docs/RUNBOOK.md, « Rotate a secret »), each on the running staging, each proving what its key guards.
redeploy() {
  bash "$staging/scripts/deploy/deploy.sh" --staging "$values" "$WORK/etc/releases/$snapshot.env" > "$WORK/redeploy.log" 2>&1 \
    || { cat "$WORK/redeploy.log" >&2; fail "redeploying after $1 failed"; }
  through_the_edge
}
php_in_api() { dc exec -T api php -r "require 'vendor/autoload.php'; $1" -- "${@:2}"; }
# A code the authenticator would show now, from a step no code was taken in yet: a code is spent once per 30 seconds.
code_now() {
  local left=$((30 - $(date +%s) % 30)); sleep "$((left + 1))"
  php_in_api 'echo (new App\Identity\Infrastructure\Mfa\OtphpTotpCodes())->codeAt($argv[1], new DateTimeImmutable());' "$1"
}
post() { # path, JSON body: the status, with the session cookie
  ask -b /tmp/jar -c /tmp/jar -o /dev/null -w '%{http_code}' -H "Origin: https://$HOST" -H "csrf-token: $csrf" \
    -H 'Content-Type: application/json' --data "$2" "https://$HOST$1"
}
get() { ask -b /tmp/jar -H "Origin: https://$HOST" -H "csrf-token: $csrf" "https://$HOST$1"; }
# The budgets outlive a redeploy on the share volume, and this check proves the operator's codes more often in five
# minutes than the per-person budget allows: each proof starts from a full budget, which is not what is checked here.
fresh_budgets() { dc exec -T api bin/console cache:pool:clear cache.rate_limiter >/dev/null || fail "the rate limiters' budgets could not be reset"; }
signed_in_with() { # authenticator secret: password, then the code
  fresh_budgets
  [[ "$(sign_in)" == 200 ]] || fail "the password step failed"
  post /api/auth/mfa/verify "{\"code\":\"$(code_now "$1")\"}"
}

# MFA: the operator enrols an authenticator; a new APP_MFA_KEY alone leaves it unreadable; app:keys:rotate-mfa, run with
# the previous key while api and worker are stopped, reseals it, and the next code signs in. Adding a factor asks for a
# fresh proof first, the password, as the screen's step-up dialog does.
[[ "$(post /api/auth/step-up "{\"password\":\"$password\"}")" == 204 ]] || fail "the step-up before enrolling refused the password"
secret=$(ask -b /tmp/jar -c /tmp/jar -H "Origin: https://$HOST" -H "csrf-token: $csrf" -H 'Content-Type: application/json' \
  --data '{}' "https://$HOST/api/auth/mfa/enrolment" | jq -r '.secret // empty')
[[ -n "$secret" ]] || fail "enrolling an authenticator gave no secret"
[[ "$(post /api/auth/mfa/enrolment/confirm "{\"code\":\"$(code_now "$secret")\"}")" == 200 ]] || fail "confirming the authenticator failed"
[[ "$(signed_in_with "$secret")" == 200 ]] || fail "the enrolled operator did not sign in with a code"
old_mfa=$(release_value APP_MFA_KEY "$values")
fact APP_MFA_KEY "$(head -c 32 /dev/urandom | base64)"
redeploy "a new APP_MFA_KEY"
[[ "$(signed_in_with "$secret")" == 401 ]] || fail "a code was taken under a key the authenticator was not sealed with"
dc stop api worker >/dev/null 2>&1
printf '%s\n' "$old_mfa" | dc run --rm --no-deps -T api bin/console app:keys:rotate-mfa > "$WORK/rotate.log" 2>&1 \
  || { cat "$WORK/rotate.log" >&2; fail "app:keys:rotate-mfa failed"; }
grep -q "Resealed 1 authenticator secret" "$WORK/rotate.log" || fail "the rotation did not reseal the one secret: $(cat "$WORK/rotate.log")"
redeploy "resealing"
[[ "$(signed_in_with "$secret")" == 200 ]] || fail "the resealed authenticator did not sign in"
step "MFA key rotated: unreadable alone, resealed by app:keys:rotate-mfa"

# APP_SECRET signs the links mails carry to stop a kind of mail: one made before the rotation is refused after it.
user=$(get /api/auth/me | jq -r '.user.id // empty')
kind=$(get /api/me/notification-preferences | jq -r '[.preferences[] | select(.companyId == null) | .type][0] // empty')
[[ -n "$user" && -n "$kind" ]] || fail "found no operator id ($user) or kind of mail told without a company ($kind)"
stop_link() { php_in_api 'echo (new App\Inbox\Infrastructure\Mail\HmacMailStopTokens(getenv("APP_SECRET")))->issue(new App\Inbox\Application\MailStop(Symfony\Component\Uid\Uuid::fromString($argv[1]), null, $argv[2]));' "$user" "$kind"; }
before=$(stop_link)
[[ "$(post /api/notification-preferences/stop "{\"token\":\"$before\"}")" == 204 ]] || fail "a stop link made under the current APP_SECRET was refused"
fact APP_SECRET "$(head -c 36 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 48)"
redeploy "a new APP_SECRET"
[[ "$(post /api/notification-preferences/stop "{\"token\":\"$before\"}")" == 404 ]] || fail "a stop link made before the APP_SECRET rotation still works"
[[ "$(post /api/notification-preferences/stop "{\"token\":\"$(stop_link)\"}")" == 204 ]] || fail "a stop link made after the rotation was refused"
step "APP_SECRET rotated: older mail links refused, newer ones taken"

# The realtime keys: Centrifugo checks a connection token with REALTIME_TOKEN_KEY and its HTTP API with REALTIME_API_KEY.
[[ "$(signed_in_with "$secret")" == 200 ]] || fail "signing in again after the APP_SECRET rotation failed"
realtime_token() { get /api/me/realtime-token | jq -r '.token // empty'; }
token_valid() { dc exec -T centrifugo sh /twes-secrets centrifugo checktoken "$1" > "$WORK/checktoken.log" 2>&1; }
api_key_answer() { dc exec -T edge curl -sS -o /dev/null -w '%{http_code}' -H "X-API-Key: $1" --data '{}' http://centrifugo:8000/api/info; }
old_token=$(realtime_token); old_api=$(release_value REALTIME_API_KEY "$values")
token_valid "$old_token" || fail "Centrifugo refused a token the API issued: $(cat "$WORK/checktoken.log")"
[[ "$(api_key_answer "$old_api")" == 200 ]] || fail "Centrifugo's API refused the current key"
fact REALTIME_TOKEN_KEY "$(head -c 36 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 48)"
fact REALTIME_API_KEY "$(head -c 36 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 48)"
redeploy "new realtime keys"
! token_valid "$old_token" || fail "Centrifugo still takes a token signed with the old key"
token_valid "$(realtime_token)" || fail "Centrifugo refused a token issued after the rotation: $(cat "$WORK/checktoken.log")"
[[ "$(api_key_answer "$old_api")" == 401 ]] || fail "Centrifugo's API still takes the old key"
[[ "$(api_key_answer "$(release_value REALTIME_API_KEY "$values")")" == 200 ]] || fail "Centrifugo's API refused the new key"
step "realtime keys rotated: old token and API key refused, new ones taken"

# The database password: changed in the server first, then in the values; the old one no longer opens a connection.
old_db=$(release_value POSTGRES_PASSWORD "$values"); new_db=$(head -c 36 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 40)
dc exec -T postgres psql -U twes -d twes -v ON_ERROR_STOP=1 -qc "ALTER ROLE twes PASSWORD '$new_db'" >/dev/null || fail "ALTER ROLE failed"
fact POSTGRES_PASSWORD "$new_db"
redeploy "a new database password"
[[ "$(signed_in_with "$secret")" == 200 ]] || fail "signing in after the database password rotation failed"
dc exec -T -e PGPASSWORD="$new_db" backup psql -h postgres -U twes -d twes -qtAc 'SELECT 1' >/dev/null \
  || fail "the new database password does not open a connection over TCP"
! dc exec -T -e PGPASSWORD="$old_db" backup psql -h postgres -U twes -d twes -qtAc 'SELECT 1' >/dev/null 2>&1 \
  || fail "the old database password still opens a connection"
step "database password rotated: the old one refused, the deployment healthy"

# The application role's password: changed in the values alone; the migrate service sets it before api and worker start.
old_app=$(release_value POSTGRES_APP_PASSWORD "$values"); new_app=$(head -c 36 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 40)
fact POSTGRES_APP_PASSWORD "$new_app"
redeploy "a new application role password"
[[ "$(signed_in_with "$secret")" == 200 ]] || fail "signing in after the application role's password rotation failed"
dc exec -T -e PGPASSWORD="$new_app" backup psql -h postgres -U twes_app -d twes -qtAc 'SELECT 1' >/dev/null \
  || fail "the new application role password does not open a connection"
! dc exec -T -e PGPASSWORD="$old_app" backup psql -h postgres -U twes_app -d twes -qtAc 'SELECT 1' >/dev/null 2>&1 \
  || fail "the old application role password still opens a connection"
step "application role password rotated: the old one refused, the deployment healthy"

printf 'staging-check: OK — published, followed and redeployed by digest, five secrets rotated in %ss in all\n' "$(($(date +%s) - started))"
