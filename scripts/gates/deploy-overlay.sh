#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# What a deployment promises (docs/START.md § 14), read from the five files merged as the server runs them:
# compose.yaml, compose.prod.yaml, compose.backup.yaml, compose.backup-bucket.yaml and compose.deploy.yaml.
#   ports      only the edge publishes, on 80, 443 and 443/udp; Docker's published ports pass by the host's firewall, so
#              the database, the api or a mail catcher left published would face the internet
#   addresses  api and both workers take every address from TWES_HOST (the workers send the mails, so their links must be
#              the api's), and centrifugo accepts that origin alone
#   database   api and both workers connect as the application's role, its password the secret POSTGRES_APP_PASSWORD; the
#              owner's, POSTGRES_PASSWORD, reaches only postgres and the one-shot migrate service, so a statement the
#              application is tricked into running never holds the schema
#   required   TWES_HOST, EDGE_TLS, MAILER_SENDER, MONITOR_URL and SENTRY_DSN have no default; each secret, the mail relay
#              and the monitor's two tokens among them, is a compose secret every service that needs it mounts, which
#              compose refuses to start without and scripts/deploy/deploy.sh refuses empty: a server missing one stops
#              before starting anything, rather than run unwatched (scripts/gates/secrets-overlay.sh: none in an environment)
#   monitor    api and both workers report their errors to SENTRY_DSN and answer MONITOR_TOKEN; the backup service sends its
#              heartbeats to MONITOR_URL with MONITOR_HEARTBEAT_TOKEN
#   mailpit    behind a profile, so it never starts
#   HSTS       infra/edge/Caddyfile sends max-age=31536000 to the app host only: no includeSubDomains, no preload
# Usage: deploy-overlay.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
problems=()
problem() { problems+=("$1"); }
finish() {
  if ((${#problems[@]})); then
    printf 'deploy-overlay: FAIL — %d problem(s) with the deployment (docs/START.md § 14):\n' "${#problems[@]}"
    printf '  %s\n' "${problems[@]}"
    exit 1
  fi
  printf 'deploy-overlay: OK — only the edge publishes, every address comes from TWES_HOST, %d settings and %d secrets are required, HSTS for the host alone\n' "${#REQUIRED[@]}" "${#SECRETS[@]}"
  exit 0
}

REQUIRED=(TWES_HOST EDGE_TLS MAILER_SENDER MONITOR_URL SENTRY_DSN)
# Each secret, by its variable, and the services that must mount it.
SECRETS=("APP_SECRET:api worker demo-worker migrate" "APP_MFA_KEY:api worker demo-worker migrate"
  "REALTIME_TOKEN_KEY:api worker demo-worker migrate centrifugo" "REALTIME_API_KEY:api worker demo-worker migrate centrifugo"
  "POSTGRES_PASSWORD:postgres migrate" "POSTGRES_APP_PASSWORD:api worker demo-worker migrate"
  "MAILER_DSN:api worker demo-worker migrate" "MONITOR_TOKEN:api worker demo-worker migrate"
  "MONITOR_HEARTBEAT_TOKEN:backup" "BACKUP_DATABASE_PASSPHRASE:postgres backup" "BACKUP_FILES_PASSPHRASE:backup"
  "BACKUP_S3_SECRET:postgres backup")
HOST=gate.twes.invalid
# Every value the five files need, as made up as the host, given as the server gives them: in an --env-file, which
# takes the place of the committed development .env, and with nothing of the developer's own environment.
values=(TWES_HOST=$HOST EDGE_TLS=gate@twes.invalid POSTGRES_PASSWORD=gate-postgres POSTGRES_APP_PASSWORD=gate-app
  MAILER_DSN=smtp://relay.invalid:587 MAILER_SENDER=no-reply@$HOST APP_SECRET=s APP_MFA_KEY=k REALTIME_TOKEN_KEY=t
  REALTIME_API_KEY=a BACKUP_S3_ENDPOINT=e BACKUP_S3_BUCKET=b BACKUP_S3_REGION=r BACKUP_S3_KEY=k BACKUP_S3_SECRET=s
  BACKUP_DATABASE_PASSPHRASE=d BACKUP_FILES_PASSPHRASE=f MONITOR_TOKEN=gate-monitor MONITOR_URL=https://status.$HOST
  MONITOR_HEARTBEAT_TOKEN=gate-heartbeat SENTRY_DSN=https://gate@errors.$HOST/1)
files=(-f compose.yaml -f compose.prod.yaml -f compose.backup.yaml -f compose.backup-bucket.yaml -f compose.deploy.yaml)
for f in compose.yaml compose.prod.yaml compose.backup.yaml compose.backup-bucket.yaml compose.deploy.yaml infra/edge/Caddyfile; do
  [[ -f "$root/$f" ]] || problem "found no $f"
done
((${#problems[@]})) && finish
env_file=$(mktemp); trap 'rm -f "$env_file"' EXIT
compose() {
  printf '%s\n' "$@" > "$env_file"
  (cd "$root" && env -i PATH="$PATH" HOME="$HOME" docker compose --env-file "$env_file" "${files[@]}" config --format json 2>&1)
}

config=$(compose "${values[@]}") || { problem "docker compose config failed: $config"; finish; }

# Without each required value the configuration must refuse to resolve, naming it.
for name in "${REQUIRED[@]}"; do
  without=()
  for value in "${values[@]}"; do [[ "$value" == "$name="* ]] || without+=("$value"); done
  out=$(compose "${without[@]}")
  if [[ $? -eq 0 || "$out" != *"$name"* ]]; then problem "$name is not required: a server without it would start on a default"; fi
done
for entry in "${SECRETS[@]}"; do
  name=${entry%%:*}
  secret=$(jq -r --arg v "$name" '[(.secrets // {}) | to_entries[] | select(.value.environment == $v) | .key] | first // ""' <<<"$config")
  if [[ -z "$secret" ]]; then problem "$name is no compose secret: a server without it would start on a default"; continue; fi
  for service in ${entry#*:}; do
    jq -e --arg s "$service" --arg k "$secret" '[.services[$s].secrets // [] | .[] | .source] | index($k)' <<<"$config" >/dev/null \
      || problem "$service does not mount $name (the secret $secret)"
  done
done

while IFS= read -r line; do [[ -n "$line" ]] && problem "$line"; done < <(jq -r '.services | to_entries[]
  | select(.key != "edge" and ((.value.ports // []) | length) > 0)
  | "\(.key) publishes \([.value.ports[] | "\(.published // "?"):\(.target)"] | join(", ")): only the edge may"' <<<"$config")
edge_ports=$(jq -r '[.services.edge.ports // [] | .[] | "\(.published):\(.target)/\(.protocol // "tcp")"] | sort | join(" ")' <<<"$config")
[[ "$edge_ports" == "443:443/tcp 443:443/udp 80:80/tcp" ]] || problem "the edge publishes [$edge_ports], wanted 80, 443 and 443/udp"

for service in api worker demo-worker; do
  for pair in "DEFAULT_URI=https://$HOST" "APP_WEBAUTHN_RP_ID=$HOST" "APP_WEBAUTHN_ORIGINS=https://$HOST" \
    "PAIRING_ORIGIN=https://$HOST" "MAILER_SENDER=no-reply@$HOST" "SENTRY_DSN=https://gate@errors.$HOST/1"; do
    key=${pair%%=*} want=${pair#*=}
    got=$(jq -r --arg s "$service" --arg k "$key" '.services[$s].environment[$k] // "(unset)"' <<<"$config")
    [[ "$got" == "$want" ]] || problem "$service $key is $got, wanted $want"
  done
  got=$(jq -r --arg s "$service" '.services[$s].environment.DATABASE_URL_TEMPLATE // ""' <<<"$config")
  [[ "$got" == "postgresql://twes_app:{postgres_app_password}@"* ]] || problem "$service DATABASE_URL_TEMPLATE is not the application's role with its password's placeholder: $got"
done
got=$(jq -r '.services.migrate.environment.DATABASE_MIGRATION_URL_TEMPLATE // ""' <<<"$config")
[[ "$got" == "postgresql://twes:{postgres_password}@"* ]] || problem "migrate DATABASE_MIGRATION_URL_TEMPLATE is not the owner with its password's placeholder: $got"
while IFS= read -r line; do [[ -n "$line" ]] && problem "$line"; done < <(jq -r '.services | to_entries[]
  | select(.key != "postgres" and .key != "migrate")
  | select([.value.environment // {} | .[] | tostring | contains("gate-postgres")] | any)
  | "\(.key) holds the owner'"'"'s password: only postgres and migrate may"' <<<"$config")
while IFS= read -r line; do [[ -n "$line" ]] && problem "$line"; done < <(jq -r '
  [(.secrets // {}) | to_entries[] | select(.value.environment == "POSTGRES_PASSWORD") | .key] as $owner
  | .services | to_entries[] | select(.key != "postgres" and .key != "migrate")
  | select([(.value.secrets // [])[] | .source] | any(. as $s | $owner | index($s)))
  | "\(.key) holds the owner'"'"'s password: only postgres and migrate may"' <<<"$config")
got=$(jq -r '.services.backup.environment.MONITOR_URL // "(unset)"' <<<"$config")
[[ "$got" == "https://status.$HOST" ]] || problem "backup MONITOR_URL is $got, wanted https://status.$HOST"
got=$(jq -r '.services.postgres.environment.POSTGRES_PASSWORD_FILE // "(unset)"' <<<"$config")
[[ "$got" == /run/secrets/postgres_password ]] || problem "postgres reads its password from $got, wanted the secret /run/secrets/postgres_password"
got=$(jq -r '.services.centrifugo.environment.CENTRIFUGO_CLIENT_ALLOWED_ORIGINS // "(unset)"' <<<"$config")
[[ "$got" == "https://$HOST" ]] || problem "centrifugo accepts origins [$got], wanted https://$HOST alone"
jq -e '.services | has("mailpit") | not' <<<"$config" >/dev/null || problem "mailpit starts in a deployment: put it behind a profile"

caddy="$root/infra/edge/Caddyfile"
grep -qE '^[[:space:]]*header Strict-Transport-Security "max-age=31536000"$' "$caddy" \
  || problem "infra/edge/Caddyfile does not send exactly Strict-Transport-Security \"max-age=31536000\""
grep -E '^[[:space:]]*header Strict-Transport-Security' "$caddy" | grep -qiE 'includeSubDomains|preload' && problem "infra/edge/Caddyfile's Strict-Transport-Security reaches beyond the app host (includeSubDomains or preload)"
finish
