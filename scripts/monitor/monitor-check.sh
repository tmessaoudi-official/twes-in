#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# The monitor check (docs/START.md § 15): a deployment and its monitor booted side by side, each as a server boots it,
# from the values scripts/deploy/new-instance.sh and scripts/monitor/new-monitor.sh write, and joined by one network on
# which each edge answers under its names, as DNS would send them there. Then what the monitor is for:
#   watched     Gatus reads the instance's deep health (/api/health/deep, with its token) green
#   heartbeat   a base backup's heartbeat reaches Gatus, and its line is green
#   API errors  app:errors:test reaches GlitchTip, its address, quoted value and long number masked
#   web errors  a report posted to /api/client-errors reaches GlitchTip, masked, its page without the query
#   time        a tracker that never answers (a blackholed DSN) costs a failing command a few seconds at most
# Certificates come from each edge's own authority (EDGE_TLS=internal); each side trusts the other's through CHECK_DIR
# (scripts/monitor/instance.compose.yaml and monitor.compose.yaml). Not checked here: mail and Telegram alerts (the
# relay and the bot are the operator's), and the Site line, which an internal certificate of hours always fails.
# Runs under the compose projects twes-monitor-check-app and twes-monitor-check only, and removes their containers,
# volumes and network at the end.
# Usage: scripts/monitor/monitor-check.sh   (make monitor-check; CI's monitor-check workflow)
set -euo pipefail

APP=twes-monitor-check-app MON=twes-monitor-check NETWORK=twes-monitor-check
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
CHECK_DIR=$(mktemp -d); chmod 755 "$CHECK_DIR"; export CHECK_DIR
# The stand-in bucket (scripts/backup/stand-in-bucket.sh) keeps its keys and certificates here too.
export DRILL_DIR=$CHECK_DIR DRILL_PG_DATA=twes-monitor-check-pg
HOST=app.monitor-check.twes.test STATUS=status.monitor-check.twes.test ERRORS=errors.monitor-check.twes.test
OPERATOR=operator@monitor-check.twes.test
app_values=$CHECK_DIR/deploy.env mon_values=$CHECK_DIR/monitor.env

started=$(date +%s); step_started=$started
step() { local now; now=$(date +%s); printf 'monitor-check: %-62s %4ss\n' "$1" "$((now - step_started))"; step_started=$now; }
fail() { echo "monitor-check: FAIL — $*" >&2; exit 1; }
dc() {
  (cd "$root" && env COMPOSE_PROFILES= docker compose -p "$APP" --env-file "$app_values" -f compose.yaml -f compose.prod.yaml \
    -f compose.backup.yaml -f compose.deploy.yaml -f scripts/backup/drill.compose.yaml -f scripts/deploy/check.compose.yaml \
    -f scripts/monitor/instance.compose.yaml "$@")
}
mon() { (cd "$root" && docker compose -p "$MON" --env-file "$mon_values" -f infra/monitor/compose.yaml -f scripts/monitor/monitor.compose.yaml "$@"); }

cleanup() {
  local status=$?
  if ((status != 0)); then
    mon logs --no-color --tail 40 edge gatus glitchtip 2>/dev/null || true
    dc logs --no-color --tail 40 api backup 2>/dev/null || true
  fi
  dc --profile restore down --volumes --remove-orphans >/dev/null 2>&1 || true
  mon down --volumes --remove-orphans >/dev/null 2>&1 || true
  docker volume rm "$DRILL_PG_DATA" >/dev/null 2>&1 || true
  docker network rm "$NETWORK" >/dev/null 2>&1 || true
  rm -rf "$CHECK_DIR"
  exit "$status"
}
trap cleanup EXIT

value() { grep "^$2=" "$1" | cut -d= -f2-; }
fact() { sed -i "s|^$2=.*|$2=$3|" "$1"; grep -q "^$2=$3\$" "$1" || fail "could not set $2 in ${1##*/}"; }
random() { head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'; }

# The instance's values as an operator starts them; its SENTRY_DSN comes from the monitor once it is up.
bash "$root/scripts/deploy/new-instance.sh" "$app_values" >/dev/null
fact "$app_values" TWES_HOST "$HOST"
fact "$app_values" EDGE_TLS internal
fact "$app_values" MAILER_DSN smtp://mailpit:1025
fact "$app_values" MAILER_SENDER "no-reply@$HOST"
fact "$app_values" BACKUP_S3_ENDPOINT s3
fact "$app_values" BACKUP_S3_BUCKET twes-backups
fact "$app_values" BACKUP_S3_REGION us-east-1
fact "$app_values" BACKUP_S3_KEY "check$(random 16)"
fact "$app_values" BACKUP_S3_SECRET "$(random 24)"
printf 'BACKUP_S3_PORT=8333\nBACKUP_S3_URI_STYLE=path\n' >> "$app_values"
fact "$app_values" MONITOR_URL "https://$STATUS"
# Until bootstrap.py prints the real one: compose reads the file to build the images before the monitor is up.
fact "$app_values" SENTRY_DSN "https://pending@$ERRORS/0"
BACKUP_S3_KEY=$(value "$app_values" BACKUP_S3_KEY) BACKUP_S3_SECRET=$(value "$app_values" BACKUP_S3_SECRET) BACKUP_S3_BUCKET=twes-backups
token=$(value "$app_values" MONITOR_TOKEN)

# The monitor's, the two tokens copied from the instance's file as docs/START.md § 15 says. The relay and the bot are
# names that resolve nowhere: an alert that fires during the check goes nowhere.
bash "$root/scripts/monitor/new-monitor.sh" "$mon_values" >/dev/null
fact "$mon_values" TWES_HOST "$HOST"
fact "$mon_values" MONITOR_TOKEN "$token"
fact "$mon_values" MONITOR_HEARTBEAT_TOKEN "$(value "$app_values" MONITOR_HEARTBEAT_TOKEN)"
fact "$mon_values" STATUS_HOST "$STATUS"
fact "$mon_values" ERRORS_HOST "$ERRORS"
fact "$mon_values" EDGE_TLS internal
fact "$mon_values" ALERT_SMTP_HOST relay.monitor-check.twes.test
fact "$mon_values" ALERT_SMTP_USERNAME check
fact "$mon_values" ALERT_SMTP_PASSWORD "$(random 12)"
fact "$mon_values" ALERT_FROM "monitor@$STATUS"
fact "$mon_values" ALERT_TO "$OPERATOR"
fact "$mon_values" TELEGRAM_TOKEN "0:check"
fact "$mon_values" TELEGRAM_CHAT_ID 0

docker network rm "$NETWORK" >/dev/null 2>&1 || true
docker network create "$NETWORK" >/dev/null
# shellcheck source=scripts/backup/stand-in-bucket.sh
source "$root/scripts/backup/stand-in-bucket.sh"
stand_in_bucket_keys
step "values written by new-instance.sh and new-monitor.sh, network made"

mon up -d --wait
for _ in $(seq 1 30); do mon exec -T edge cat /data/caddy/pki/authorities/local/root.crt > "$CHECK_DIR/monitor-root.crt" 2>/dev/null && break; sleep 1; done
[[ -s "$CHECK_DIR/monitor-root.crt" ]] || fail "the monitor's edge made no authority"
mkdir -p "$CHECK_DIR/php"
printf 'curl.cainfo=/check/monitor-root.crt\nopenssl.cafile=/check/monitor-root.crt\n' > "$CHECK_DIR/php/zz-monitor-check.ini"
chmod -R a+rX "$CHECK_DIR"
step "monitor up behind its edge"

# The operator's account and the instance's project, as docs/START.md § 15 makes them; a read-only token for the check.
password=$(random 24)
# As docs/START.md § 15 writes it: the password through the environment, never on the command line.
DJANGO_SUPERUSER_PASSWORD="$password" mon exec -T -e DJANGO_SUPERUSER_EMAIL="$OPERATOR" -e DJANGO_SUPERUSER_PASSWORD glitchtip \
  python manage.py createsuperuser --noinput >/dev/null || fail "createsuperuser could not make the operator's account"
dsn=$(mon exec -T -e OPERATOR_EMAIL="$OPERATOR" glitchtip python manage.py shell < "$root/infra/monitor/bootstrap.py" | sed -n 's/^SENTRY_DSN=//p')
[[ "$dsn" == "https://"*"@$ERRORS/"* ]] || fail "bootstrap.py printed no DSN on $ERRORS: [$dsn]"
fact "$app_values" SENTRY_DSN "$dsn"
tracker=$(mon exec -T -e OPERATOR_EMAIL="$OPERATOR" glitchtip python manage.py shell -c '
import os
from apps.api_tokens.models import APIToken
from apps.users.models import User
t = APIToken.objects.create(user=User.objects.get(email=os.environ["OPERATOR_EMAIL"]), label="monitor-check")
t.add_permissions(["org:read", "project:read", "event:read"])
print(t.token)' | tail -1)
[[ "$tracker" =~ ^[0-9a-f]{64}$ ]] || fail "no API token for reading the tracker: [$tracker]"
step "operator's account, project and DSN made (bootstrap.py)"

stand_in_bucket_up
dc up -d --build --wait
dc exec -T backup gosu postgres pgbackrest --stanza=twes check >/dev/null || fail "the backup service does not reach the bucket"
dc exec -T edge cat /data/caddy/pki/authorities/local/root.crt > "$CHECK_DIR/instance-root.crt"
chmod a+r "$CHECK_DIR/instance-root.crt"
# Gatus reads its authorities once, at its first request: started again, it trusts the instance's.
mon restart gatus >/dev/null
step "instance up behind its edge, archiving, joined to the monitor"

# Every request from the backup container, on the shared network: it trusts the monitor's authority (CURL_CA_BUNDLE)
# and is given the instance's.
ask() { dc exec -T backup curl -sS --max-time 15 "$@"; }
deep=$(ask -o /dev/null -w '%{http_code}' --cacert /check/instance-root.crt -H "Authorization: Bearer $token" "https://$HOST/api/health/deep")
[[ "$deep" == 200 ]] || fail "the instance's deep health answered $deep through its edge"

dc exec -T backup twes-backup full >/dev/null || fail "the base backup failed"
step "a base backup taken"

# The status page's lines, as Gatus records them: the newest result of a line by its name.
line() { jq -r --arg n "$1" '[.[] | select(.name == $n) | .results[-1].success] | if length == 0 then "none" else first end' "$CHECK_DIR/statuses.json"; }
for _ in $(seq 1 30); do
  ask "https://$STATUS/api/v1/endpoints/statuses" > "$CHECK_DIR/statuses.json" || true
  [[ "$(line Traitements)" == true && "$(line Base)" == true ]] && break
  sleep 10
done
[[ "$(line Traitements)" == true ]] || fail "Gatus does not read the deep health green: $(line Traitements)"
[[ "$(line Base)" == true ]] || fail "Gatus took no successful Base heartbeat: $(line Base)"
step "Gatus reads the deep health green and took the Base heartbeat"

# What a report must never carry, put in both on purpose.
ADDRESS=nobody@twes.invalid QUOTED='a quoted value'
dc exec -T api bin/console app:errors:test >/dev/null 2>&1 && fail "app:errors:test did not fail"
csrf=$(random 16)
report=$(printf '{"message":"TypeError: %s refused \\"%s\\" 987654321","stack":"at check (main.js:1:1)","page":"/invoices?customer=%s","build":"check"}' \
  "$ADDRESS" "$QUOTED" "$ADDRESS")
posted=$(ask -o /dev/null -w '%{http_code}' --cacert /check/instance-root.crt -H "Origin: https://$HOST" -H "csrf-token: $csrf" \
  -H 'Content-Type: application/json' --data "$report" "https://$HOST/api/client-errors")
[[ "$posted" == 204 ]] || fail "the web error report answered $posted"

read_tracker() { ask -f -H "Authorization: Bearer $tracker" "https://$ERRORS/api/0/$1"; }
for _ in $(seq 1 30); do
  read_tracker "organizations/twes-in/issues/" > "$CHECK_DIR/issues.json" || true
  (($(jq '[.[] | select(.title | test("TestError|Web error"))] | length' "$CHECK_DIR/issues.json" 2>/dev/null || echo 0) >= 2)) && break
  sleep 5
done
jq -e '[.[] | select(.title | test("TestError"))] | length > 0' "$CHECK_DIR/issues.json" >/dev/null || fail "app:errors:test never reached GlitchTip: $(cat "$CHECK_DIR/issues.json")"
jq -e '[.[] | select(.title | test("Web error"))] | length > 0' "$CHECK_DIR/issues.json" >/dev/null || fail "the web error never reached GlitchTip: $(cat "$CHECK_DIR/issues.json")"
: > "$CHECK_DIR/events.json"
# A frame's source lines are this repository's code (app:errors:test writes its values out on the line it throws from),
# not what the request carried: left out, every other field searched.
for id in $(jq -r '.[].id' "$CHECK_DIR/issues.json"); do
  read_tracker "issues/$id/events/latest/" \
    | jq 'walk(if type == "object" and has("filename") then del(.context, .context_line, .pre_context, .post_context) else . end)' \
    >> "$CHECK_DIR/events.json"
done
for leak in "$ADDRESS" "$QUOTED" 123456789 987654321 "customer="; do
  ! grep -qF -e "$leak" "$CHECK_DIR/events.json" "$CHECK_DIR/issues.json" \
    || fail "a report reached the tracker with [$leak] in it: $(grep -ohE ".{0,160}${leak//./\\.}.{0,80}" "$CHECK_DIR/events.json" "$CHECK_DIR/issues.json" | head -3)"
done
step "API and web errors reached GlitchTip, masked"

# A tracker that takes the connection and never answers: the failing command's cost, against one that answers.
measure() { local t0; t0=$(date +%s%N); dc exec -T "$@" api bin/console app:errors:test >/dev/null 2>&1 || true; echo $((($(date +%s%N) - t0) / 1000000)); }
answered=$(measure)
blackholed=$(measure -e SENTRY_DSN="https://check@10.255.255.1/1")
((blackholed - answered < 5000)) || fail "a blackholed tracker cost ${blackholed} ms against ${answered} ms: more than its timeouts"
step "a blackholed tracker costs $((blackholed - answered)) ms more (${answered} ms answered)"

printf 'monitor-check: OK — the instance watched, its backups heard and its errors tracked, in %ss in all\n' "$(($(date +%s) - started))"
