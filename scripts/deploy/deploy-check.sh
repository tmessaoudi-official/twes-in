#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The deploy check (docs/START.md § 14): a deployment booted as a server boots it, from the values
# scripts/deploy/new-instance.sh writes, given through --env-file, with the five compose files, then asked through its
# edge what a browser would ask:
#   HSTS            every HTTPS answer carries max-age=31536000 for the host alone
#   redirect        plain HTTP answers 308 to the same address over HTTPS
#   health          /api/health answers ok through the edge, nginx and the api
#   signing in      the operator `app:seed --without-company --operator-password-stdin` made signs in, and the session
#                   cookie then reads /api/auth/me: the Host, Origin and X-Forwarded-Proto that reach the API pass its
#                   same-origin check, so nothing on the way rewrote the browser's address
#   realtime        the WebSocket upgrade on /connection/websocket reaches Centrifugo
#   client address  a forged X-Forwarded-For does not become the client's address: five refused password-reset
#                   requests, each claiming another one, are still counted against one, and the sixth answers 429
#   backups         the backup service started and pgBackRest reaches the bucket
# Certificates come from Caddy's own authority (EDGE_TLS=internal), and requests are made from inside the stack's
# network, trusting that authority, so no public name or port is needed. Runs under the compose project
# `twes-deploy-check` only and removes its containers and volumes at the end.
# Usage: scripts/deploy/deploy-check.sh   (make deploy-check; CI's deploy-check workflow)
set -euo pipefail

PROJECT=twes-deploy-check
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${COMPOSE_PROJECT_NAME:-$PROJECT}" == "$PROJECT" ]] || { echo "deploy-check: refuses COMPOSE_PROJECT_NAME=$COMPOSE_PROJECT_NAME, it runs as $PROJECT only" >&2; exit 2; }
export COMPOSE_PROJECT_NAME=$PROJECT
export COMPOSE_FILE="$root/compose.yaml:$root/compose.prod.yaml:$root/compose.backup.yaml:$root/compose.backup-bucket.yaml:$root/compose.deploy.yaml:$root/scripts/backup/drill.compose.yaml:$root/scripts/deploy/check.compose.yaml"
export COMPOSE_PROFILES=
DRILL_DIR=$(mktemp -d); export DRILL_DIR
export DRILL_PG_DATA=twes-deploy-check-pg
HOST=deploy-check.twes.test
values="$DRILL_DIR/deploy.env"

started=$(date +%s); step_started=$started
step() { local now; now=$(date +%s); printf 'deploy-check: %-62s %4ss\n' "$1" "$((now - step_started))"; step_started=$now; }
fail() { echo "deploy-check: FAIL — $*" >&2; exit 1; }
dc() { (cd "$root" && docker compose --env-file "$values" "$@"); }

cleanup() {
  local status=$?
  if ((status != 0)); then dc logs --no-color --tail 60 edge web api backup 2>/dev/null || true; fi
  dc --profile restore down --volumes --remove-orphans >/dev/null 2>&1 || true
  docker volume rm "$DRILL_PG_DATA" >/dev/null 2>&1 || true
  rm -rf "$DRILL_DIR"
  exit "$status"
}
trap cleanup EXIT

# The instance's values as an operator starts them, the facts filled in for a stack nobody outside reaches.
bash "$root/scripts/deploy/new-instance.sh" "$values" >/dev/null
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
# No monitor here (make monitor-check boots one): names that resolve nowhere, which the instance must survive.
fact MONITOR_URL "https://status.$HOST"
fact SENTRY_DSN "https://check@errors.$HOST/1"
BACKUP_S3_KEY=$(grep '^BACKUP_S3_KEY=' "$values" | cut -d= -f2) BACKUP_S3_SECRET=$(grep '^BACKUP_S3_SECRET=' "$values" | cut -d= -f2)
BACKUP_S3_BUCKET=twes-backups

# shellcheck source=scripts/backup/stand-in-bucket.sh
source "$root/scripts/backup/stand-in-bucket.sh"
stand_in_bucket_keys
step "images built, values written by new-instance.sh, bucket keys made"

stand_in_bucket_up
dc up -d --build --wait
dc exec -T backup gosu postgres pgbackrest --stanza=twes check >/dev/null || fail "the backup service does not reach the bucket"
step "deployment up behind its edge, backups archiving"

# Requests from inside the network, as a browser outside would make them: every connection to the host goes to the edge.
dc exec -T edge cat /data/caddy/pki/authorities/local/root.crt > "$DRILL_DIR/edge-root.crt"
dc exec -T gotenberg sh -c 'cat > /tmp/edge-root.crt' < "$DRILL_DIR/edge-root.crt"
ask() { dc exec -T gotenberg curl -sS --cacert /tmp/edge-root.crt --connect-to "$HOST:443:edge:443" --connect-to "$HOST:80:edge:80" --max-time 10 "$@"; }
for _ in $(seq 1 30); do ask -f -o /dev/null "https://$HOST/api/health" 2>/dev/null && break; sleep 1; done

headers=$(ask -D - -o /dev/null "https://$HOST/") || fail "the edge did not answer over HTTPS with a certificate its authority signed"
grep -qi '^strict-transport-security: max-age=31536000'$'\r''$' <<<"$headers" || fail "no Strict-Transport-Security: max-age=31536000 alone:
$headers"
step "HTTPS with HSTS for the host alone"

redirect=$(ask -o /dev/null -w '%{http_code} %{redirect_url}' "http://$HOST/invoices?x=1")
[[ "$redirect" == "308 https://$HOST/invoices?x=1" ]] || fail "plain HTTP answered [$redirect], wanted 308 to https://$HOST/invoices?x=1"
step "plain HTTP redirected"

ask "https://$HOST/api/health" | grep -q '"status":"ok"' || fail "/api/health is not ok through the edge"
step "API healthy through the edge"

password=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '%s\n' "$password" | dc exec -T api bin/console app:seed --no-interaction --operator-email="operator@$HOST" --operator-password-stdin --without-company >/dev/null \
  || fail "app:seed --without-company could not make the operator"
csrf=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')
login=$(ask -c /tmp/jar -o /dev/null -w '%{http_code}' -H "Origin: https://$HOST" -H "csrf-token: $csrf" -H 'Content-Type: application/json' \
  --data "{\"email\":\"operator@$HOST\",\"password\":\"$password\"}" "https://$HOST/api/auth/login")
[[ "$login" == 200 ]] || fail "signing in through the edge answered $login"
me=$(ask -b /tmp/jar -H "Origin: https://$HOST" -H "csrf-token: $csrf" "https://$HOST/api/auth/me")
[[ "$me" == *"\"operator@$HOST\""* ]] || fail "the session did not read /api/auth/me through the edge: $me"
[[ "$me" != *'"company":{'* ]] || fail "the production operator belongs to a company: $me"
step "signed in, the session read back, no company owned"

# An upgraded socket stays open, so curl ends on its time limit (exit 28) once it has written the 101 it read.
upgrade=$(ask -o /dev/null -w '%{http_code}' --http1.1 -H 'Connection: Upgrade' -H 'Upgrade: websocket' -H 'Sec-WebSocket-Version: 13' \
  -H "Sec-WebSocket-Key: $(head -c 16 /dev/urandom | base64)" -H "Origin: https://$HOST" "https://$HOST/connection/websocket" --max-time 3 || true)
[[ "$upgrade" == 101 ]] || fail "the realtime socket did not upgrade through the edge: $upgrade"
step "realtime socket upgraded"

for i in 1 2 3 4 5 6; do
  code=$(ask -o /dev/null -w '%{http_code}' -H "X-Forwarded-For: 198.51.100.$i" -H "Origin: https://$HOST" -H "csrf-token: $csrf" \
    -H 'Content-Type: application/json' --data '{}' "https://$HOST/api/auth/password/forgot")
done
[[ "$code" == 429 ]] || fail "six requests, each claiming another address, were not counted as one client's: the sixth answered $code"
step "a forged client address changes nothing"

printf 'deploy-check: OK — deployed and checked through its edge in %ss in all\n' "$(($(date +%s) - started))"
