#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
GATE=$HERE/deploy-overlay.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

# The deployment's files as they are in the tree, each case changing one thing in its own copy.
project() {
  local d f; d=$(mktemp -d)
  mkdir -p "$d/infra/edge"
  for f in compose.yaml compose.prod.yaml compose.backup.yaml compose.backup-bucket.yaml compose.deploy.yaml infra/edge/Caddyfile; do
    cp "$ROOT/$f" "$d/$f"
  done
  echo "$d"
}
edit() { sed -i "$2" "$1"; }

echo "deploy-overlay gate"
d=$(project)
out=$(bash "$GATE" --root "$d" 2>&1); check "the deploy overlay as shaped passes" $? 0 "$out" "OK"

d=$(project); edit "$d/compose.deploy.yaml" '/^  postgres:/,/^  centrifugo:/{/ports: !reset \[\]/d}'
out=$(bash "$GATE" --root "$d" 2>&1); check "a published database port is named" $? 1 "$out" "postgres publishes"

d=$(project); edit "$d/compose.deploy.yaml" '/^  worker:$/{n;/environment: \*deploy-environment/d}'
out=$(bash "$GATE" --root "$d" 2>&1); check "a worker left on the development address is named" $? 1 "$out" "worker DEFAULT_URI"
check "a worker that reports its errors nowhere is named" 1 1 "$out" "worker SENTRY_DSN"
d=$(project); edit "$d/compose.deploy.yaml" '/^  demo-worker:$/{n;/environment: \*deploy-environment/d}'
out=$(bash "$GATE" --root "$d" 2>&1); check "the demo worker left on the development address is named" $? 1 "$out" "demo-worker DEFAULT_URI"

d=$(project); edit "$d/compose.deploy.yaml" 's/\${EDGE_TLS:?[^}]*}/${EDGE_TLS:-internal}/'
out=$(bash "$GATE" --root "$d" 2>&1); check "a required variable given a default is named" $? 1 "$out" "EDGE_TLS is not required"

d=$(project); edit "$d/infra/edge/Caddyfile" 's/max-age=31536000"/max-age=31536000; includeSubDomains"/'
out=$(bash "$GATE" --root "$d" 2>&1); check "HSTS reaching the subdomains is refused" $? 1 "$out" "Strict-Transport-Security"

d=$(project); edit "$d/compose.deploy.yaml" '/profiles: \[mailpit\]/d'
out=$(bash "$GATE" --root "$d" 2>&1); check "mailpit started in a deployment is named" $? 1 "$out" "mailpit"

# Mailpit behind its profile but a service waiting on it would stop `up` on the server, where nothing starts it; compose
# refuses the merged project, and the gate with it.
d=$(project); edit "$d/compose.yaml" '/^  worker: &worker$/,/^  demo-worker:/{s/^      api:$/      mailpit:\n        condition: service_started\n      api:/}'
out=$(bash "$GATE" --root "$d" 2>&1); check "a service depending on mailpit is refused" $? 1 "$out" 'depends on undefined service "mailpit"'

# The repository commits a development .env, which compose reads unless given --env-file, as a server always is.
d=$(project); printf 'POSTGRES_PASSWORD=twes\nMAILER_DSN=smtp://mailpit:1025\n' > "$d/.env"
out=$(bash "$GATE" --root "$d" 2>&1); check "the committed development .env does not fill a required value" $? 0 "$out" "OK"

d=$(project); edit "$d/compose.deploy.yaml" 's/\${SENTRY_DSN:?[^}]*}/${SENTRY_DSN:-}/'
out=$(bash "$GATE" --root "$d" 2>&1); check "an instance that may start reporting its errors nowhere is named" $? 1 "$out" "SENTRY_DSN is not required"

d=$(project); edit "$d/compose.deploy.yaml" '/^  backup:/,/^  gotenberg:/{/MONITOR_URL/d}'
out=$(bash "$GATE" --root "$d" 2>&1); check "backups whose heartbeats may go nowhere are named" $? 1 "$out" "MONITOR_URL is not required"

d=$(project); edit "$d/compose.prod.yaml" 's|twes_app:{postgres_app_password}@|twes:{postgres_password}@|'
out=$(bash "$GATE" --root "$d" 2>&1); check "an api connecting as the schema's owner is named" $? 1 "$out" "api DATABASE_URL_TEMPLATE is not the application's role"

d=$(project); edit "$d/compose.deploy.yaml" '/^  worker:$/{n;s/^    environment: \*deploy-environment$/    environment:\n      <<: *deploy-environment\n      LEAK: "${POSTGRES_PASSWORD:?x}"/}'
out=$(bash "$GATE" --root "$d" 2>&1); check "a service other than migrate holding the owner's password is named" $? 1 "$out" "worker holds the owner's password"
d=$(project); edit "$d/compose.deploy.yaml" '/^  worker:$/,/^  demo-worker:$/{s/secrets: \*deploy-secrets/secrets: [mailer_dsn, monitor_token, postgres_password]/}'
out=$(bash "$GATE" --root "$d" 2>&1); check "a service other than migrate mounting the owner's password is named" $? 1 "$out" "worker holds the owner's password"

d=$(project); edit "$d/compose.prod.yaml" '/DATABASE_MIGRATION_URL_TEMPLATE/d'
out=$(bash "$GATE" --root "$d" 2>&1); check "a migrate service without the owner's address is named" $? 1 "$out" "migrate DATABASE_MIGRATION_URL_TEMPLATE"

d=$(project); edit "$d/compose.prod.yaml" 's/, mailer_dsn\]/]/; s/, mailer_dsn, postgres_password\]/, postgres_password]/; /^  mailer_dsn:$/,/^    environment: MAILER_DSN$/d'
out=$(bash "$GATE" --root "$d" 2>&1); # Compose leaves out a secret no service mounts, so the relay is then missing from the deployment altogether.
check "a deployment that mounts the mail relay nowhere is named" $? 1 "$out" "MAILER_DSN is no compose secret"
d=$(project); edit "$d/compose.prod.yaml" '/^  worker:$/,/^  demo-worker:$/{s/secrets: \*api-secrets/secrets: [app_secret, app_mfa_key, realtime_token_key, realtime_api_key, postgres_app_password]/}'
out=$(bash "$GATE" --root "$d" 2>&1); check "a worker that does not mount the mail relay is named" $? 1 "$out" "worker does not mount MAILER_DSN"

d=$(project); edit "$d/compose.deploy.yaml" '/^  monitor_heartbeat_token:$/,/^    environment: MONITOR_HEARTBEAT_TOKEN$/d' && edit "$d/compose.deploy.yaml" 's/^    secrets: \[monitor_heartbeat_token\]$//'
out=$(bash "$GATE" --root "$d" 2>&1); check "a secret that is no compose secret is named" $? 1 "$out" "MONITOR_HEARTBEAT_TOKEN is no compose secret"

d=$(project); rm "$d/compose.deploy.yaml"
out=$(bash "$GATE" --root "$d" 2>&1); check "a tree without the overlay fails" $? 1 "$out" "compose.deploy.yaml"

d=$(project); edit "$d/compose.yaml" '/^  worker: &worker$/,/^  [a-z-]*:$/{s/networks: \[default, pdf\]/networks: [default]/}'
out=$(bash "$GATE" --root "$d" 2>&1); check "a worker that cannot reach Gotenberg is named" $? 1 "$out" "worker is not on the pdf network"

echo "deploy-overlay gate: $pass passed, $fail failed"
((fail == 0))
