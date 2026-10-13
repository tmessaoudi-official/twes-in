#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/deploy-overlay.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }

# The four files as the real ones are shaped: development addresses and ports in compose.yaml, the deploy overlay
# replacing them from TWES_HOST.
project() {
  local d; d=$(mktemp -d)
  mkdir -p "$d/infra/edge"
  cat > "$d/compose.yaml" <<'EOF'
name: t
services:
  api:
    image: busybox
    environment: &env
      DEFAULT_URI: "${LAN_ORIGIN:-http://localhost:8090}"
      APP_WEBAUTHN_RP_ID: localhost
      APP_WEBAUTHN_ORIGINS: http://localhost:8090
      PAIRING_ORIGIN: "${LAN_ORIGIN:-}"
      MAILER_DSN: smtp://mailpit:1025
      MAILER_SENDER: no-reply@twes.local
      DATABASE_URL: "postgresql://twes:${POSTGRES_PASSWORD:-twes}@postgres:5432/twes"
    ports: ["8091:80"]
  worker:
    image: busybox
    environment: *env
  postgres:
    image: busybox
    environment:
      POSTGRES_PASSWORD: "${POSTGRES_PASSWORD:-twes}"
    ports: ["5433:5432"]
  centrifugo:
    image: busybox
    environment:
      CENTRIFUGO_CLIENT_ALLOWED_ORIGINS: http://localhost:8090
  web:
    image: busybox
    ports: ["8090:80"]
  mailpit:
    image: busybox
    ports: ["127.0.0.1:8092:8025"]
EOF
  printf 'services:\n  api:\n    restart: unless-stopped\n' > "$d/compose.prod.yaml"
  printf 'services:\n  backup:\n    image: busybox\n    environment:\n      MONITOR_URL: "${MONITOR_URL:-}"\n      MONITOR_HEARTBEAT_TOKEN: "${MONITOR_HEARTBEAT_TOKEN:-}"\n' > "$d/compose.backup.yaml"
  cat > "$d/compose.deploy.yaml" <<'EOF'
x-deploy-environment: &deploy
  DEFAULT_URI: "https://${TWES_HOST:?set TWES_HOST}"
  APP_WEBAUTHN_RP_ID: "${TWES_HOST:?set TWES_HOST}"
  APP_WEBAUTHN_ORIGINS: "https://${TWES_HOST:?set TWES_HOST}"
  PAIRING_ORIGIN: "https://${TWES_HOST:?set TWES_HOST}"
  MAILER_DSN: "${MAILER_DSN:?set MAILER_DSN}"
  MAILER_SENDER: "${MAILER_SENDER:?set MAILER_SENDER}"
  DATABASE_URL: "postgresql://twes_app:${POSTGRES_APP_PASSWORD:?set POSTGRES_APP_PASSWORD}@postgres:5432/twes"
  MONITOR_TOKEN: "${MONITOR_TOKEN:?set MONITOR_TOKEN}"
  SENTRY_DSN: "${SENTRY_DSN:?set SENTRY_DSN}"
services:
  edge:
    image: busybox
    environment:
      TWES_HOST: "${TWES_HOST:?set TWES_HOST}"
      EDGE_TLS: "${EDGE_TLS:?set EDGE_TLS}"
    ports: ["80:80", "443:443", "443:443/udp"]
  api:
    ports: !reset []
    environment: *deploy
  worker:
    environment: *deploy
  migrate:
    image: busybox
    environment:
      <<: *deploy
      DATABASE_MIGRATION_URL: "postgresql://twes:${POSTGRES_PASSWORD:?set POSTGRES_PASSWORD}@postgres:5432/twes"
      POSTGRES_APP_PASSWORD: "${POSTGRES_APP_PASSWORD:?set POSTGRES_APP_PASSWORD}"
  postgres:
    ports: !reset []
    environment:
      POSTGRES_PASSWORD: "${POSTGRES_PASSWORD:?set POSTGRES_PASSWORD}"
  centrifugo:
    environment:
      CENTRIFUGO_CLIENT_ALLOWED_ORIGINS: "https://${TWES_HOST:?set TWES_HOST}"
  web:
    ports: !reset []
  backup:
    environment:
      MONITOR_URL: "${MONITOR_URL:?set MONITOR_URL}"
      MONITOR_HEARTBEAT_TOKEN: "${MONITOR_HEARTBEAT_TOKEN:?set MONITOR_HEARTBEAT_TOKEN}"
  mailpit:
    profiles: [mailpit]
    ports: !reset []
EOF
  cat > "$d/infra/edge/Caddyfile" <<'EOF'
{$TWES_HOST} {
	tls {$EDGE_TLS}
	header Strict-Transport-Security "max-age=31536000"
	reverse_proxy web:80
}
EOF
  echo "$d"
}
# Replaces the first line matching $2 in file $1 with $3 (a sed s/// pair), for one case's mutation.
edit() { sed -i "$2" "$1"; }

echo "deploy-overlay gate"
d=$(project)
out=$(bash "$GATE" --root "$d" 2>&1); check "the deploy overlay as shaped passes" $? 0 "$out" "OK"

d=$(project); edit "$d/compose.deploy.yaml" '/^  postgres:/,/^  centrifugo:/{/ports: !reset \[\]/d}'
out=$(bash "$GATE" --root "$d" 2>&1); check "a published database port is named" $? 1 "$out" "postgres publishes"

d=$(project); edit "$d/compose.deploy.yaml" '/^  worker:/,/^  postgres:/{/environment: \*deploy/d}' && edit "$d/compose.deploy.yaml" 's/^  worker:$/  worker: {}/'
out=$(bash "$GATE" --root "$d" 2>&1); check "a worker left on the development address is named" $? 1 "$out" "worker DEFAULT_URI"

d=$(project); edit "$d/compose.deploy.yaml" 's/\${EDGE_TLS:?set EDGE_TLS}/${EDGE_TLS:-internal}/'
out=$(bash "$GATE" --root "$d" 2>&1); check "a required variable given a default is named" $? 1 "$out" "EDGE_TLS is not required"

d=$(project); edit "$d/infra/edge/Caddyfile" 's/max-age=31536000"/max-age=31536000; includeSubDomains"/'
out=$(bash "$GATE" --root "$d" 2>&1); check "HSTS reaching the subdomains is refused" $? 1 "$out" "Strict-Transport-Security"

d=$(project); edit "$d/compose.deploy.yaml" '/profiles: \[mailpit\]/d'
out=$(bash "$GATE" --root "$d" 2>&1); check "mailpit started in a deployment is named" $? 1 "$out" "mailpit"

# Mailpit behind its profile but a service waiting on it would stop `up` on the server, where nothing starts it; compose
# refuses the merged project, and the gate with it.
d=$(project); edit "$d/compose.yaml" 's/^  worker:$/  worker:\n    depends_on: [mailpit]/'
out=$(bash "$GATE" --root "$d" 2>&1); check "a service depending on mailpit is refused" $? 1 "$out" '"worker" depends on undefined service "mailpit"'

# The repository commits a development .env, which compose reads unless given --env-file, as a server always is.
d=$(project); printf 'POSTGRES_PASSWORD=twes\n' > "$d/.env"
out=$(bash "$GATE" --root "$d" 2>&1); check "the committed development .env does not fill a required value" $? 0 "$out" "OK"

d=$(project); edit "$d/compose.deploy.yaml" 's/\${SENTRY_DSN:?set SENTRY_DSN}/${SENTRY_DSN:-}/'
out=$(bash "$GATE" --root "$d" 2>&1); check "an instance that may start reporting its errors nowhere is named" $? 1 "$out" "SENTRY_DSN is not required"

d=$(project); edit "$d/compose.deploy.yaml" '/^  backup:/,/^  mailpit:/{/MONITOR_URL/d}'
out=$(bash "$GATE" --root "$d" 2>&1); check "backups whose heartbeats may go nowhere are named" $? 1 "$out" "MONITOR_URL is not required"

d=$(project); edit "$d/compose.deploy.yaml" '/^  worker:/,/^  postgres:/{/environment: \*deploy/d}' && edit "$d/compose.deploy.yaml" 's/^  worker:$/  worker: {}/'
out=$(bash "$GATE" --root "$d" 2>&1); check "a worker that reports its errors nowhere is named" $? 1 "$out" "worker SENTRY_DSN"

d=$(project); edit "$d/compose.deploy.yaml" 's|postgresql://twes_app:\${POSTGRES_APP_PASSWORD:?set POSTGRES_APP_PASSWORD}|postgresql://twes:${POSTGRES_PASSWORD:?set POSTGRES_PASSWORD}|'
out=$(bash "$GATE" --root "$d" 2>&1); check "an api connecting as the schema's owner is named" $? 1 "$out" "api DATABASE_URL is not the application's role"

d=$(project); edit "$d/compose.deploy.yaml" 's/^  worker:$/  worker:\n    environment:\n      LEAK: "${POSTGRES_PASSWORD:?set POSTGRES_PASSWORD}"/' && edit "$d/compose.deploy.yaml" '/^  worker:/,/^  migrate:/{/environment: \*deploy/d}'
out=$(bash "$GATE" --root "$d" 2>&1); check "a service other than migrate holding the owner's password is named" $? 1 "$out" "worker holds the owner's password"

d=$(project); edit "$d/compose.deploy.yaml" '/DATABASE_MIGRATION_URL/d'
out=$(bash "$GATE" --root "$d" 2>&1); check "a migrate service without the owner's address is named" $? 1 "$out" "migrate DATABASE_MIGRATION_URL"

d=$(project); rm "$d/compose.deploy.yaml"
out=$(bash "$GATE" --root "$d" 2>&1); check "a tree without the overlay fails" $? 1 "$out" "compose.deploy.yaml"

echo "deploy-overlay gate: $pass passed, $fail failed"
((fail == 0))
