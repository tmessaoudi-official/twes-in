#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# What a deployment promises (docs/START.md § 14), read from the five files merged as the server runs them:
# compose.yaml, compose.prod.yaml, compose.backup.yaml, compose.backup-bucket.yaml and compose.deploy.yaml.
#   ports      only the edge publishes, on 80, 443 and 443/udp; Docker's published ports pass by the host's firewall, so
#              the database, the api or a mail catcher left published would face the internet
#   addresses  api and worker both take every address from TWES_HOST (the worker sends the mails, so its links must be
#              the api's), and centrifugo accepts that origin alone
#   database   api and worker connect as the application's role with POSTGRES_APP_PASSWORD; the owner's password reaches
#              only postgres and the one-shot migrate service, so a statement the application is tricked into running
#              never holds the schema
#   required   TWES_HOST, EDGE_TLS, POSTGRES_PASSWORD, POSTGRES_APP_PASSWORD, the mail settings and the monitor's (its token, its address,
#              the heartbeats' token, the error tracker's DSN) have no default: a server missing one stops before
#              starting anything, rather than run unwatched
#   monitor    api and worker report their errors to SENTRY_DSN and answer MONITOR_TOKEN; the backup service sends its
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
  printf 'deploy-overlay: OK — only the edge publishes, every address comes from TWES_HOST, %d settings are required, HSTS for the host alone\n' "${#REQUIRED[@]}"
  exit 0
}

REQUIRED=(TWES_HOST EDGE_TLS POSTGRES_PASSWORD POSTGRES_APP_PASSWORD MAILER_DSN MAILER_SENDER MONITOR_TOKEN MONITOR_URL MONITOR_HEARTBEAT_TOKEN SENTRY_DSN)
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

while IFS= read -r line; do [[ -n "$line" ]] && problem "$line"; done < <(jq -r '.services | to_entries[]
  | select(.key != "edge" and ((.value.ports // []) | length) > 0)
  | "\(.key) publishes \([.value.ports[] | "\(.published // "?"):\(.target)"] | join(", ")): only the edge may"' <<<"$config")
edge_ports=$(jq -r '[.services.edge.ports // [] | .[] | "\(.published):\(.target)/\(.protocol // "tcp")"] | sort | join(" ")' <<<"$config")
[[ "$edge_ports" == "443:443/tcp 443:443/udp 80:80/tcp" ]] || problem "the edge publishes [$edge_ports], wanted 80, 443 and 443/udp"

for service in api worker; do
  for pair in "DEFAULT_URI=https://$HOST" "APP_WEBAUTHN_RP_ID=$HOST" "APP_WEBAUTHN_ORIGINS=https://$HOST" \
    "PAIRING_ORIGIN=https://$HOST" "MAILER_DSN=smtp://relay.invalid:587" "MAILER_SENDER=no-reply@$HOST" \
    "MONITOR_TOKEN=gate-monitor" "SENTRY_DSN=https://gate@errors.$HOST/1"; do
    key=${pair%%=*} want=${pair#*=}
    got=$(jq -r --arg s "$service" --arg k "$key" '.services[$s].environment[$k] // "(unset)"' <<<"$config")
    [[ "$got" == "$want" ]] || problem "$service $key is $got, wanted $want"
  done
  got=$(jq -r --arg s "$service" '.services[$s].environment.DATABASE_URL // ""' <<<"$config")
  [[ "$got" == "postgresql://twes_app:gate-app@"* ]] || problem "$service DATABASE_URL is not the application's role with POSTGRES_APP_PASSWORD: $got"
done
got=$(jq -r '.services.migrate.environment.DATABASE_MIGRATION_URL // ""' <<<"$config")
[[ "$got" == "postgresql://twes:gate-postgres@"* ]] || problem "migrate DATABASE_MIGRATION_URL is not the owner with POSTGRES_PASSWORD: $got"
got=$(jq -r '.services.migrate.environment.POSTGRES_APP_PASSWORD // "(unset)"' <<<"$config")
[[ "$got" == gate-app ]] || problem "migrate POSTGRES_APP_PASSWORD is $got, wanted the deployment's"
while IFS= read -r line; do [[ -n "$line" ]] && problem "$line"; done < <(jq -r '.services | to_entries[]
  | select(.key != "postgres" and .key != "migrate")
  | select([.value.environment // {} | .[] | tostring | contains("gate-postgres")] | any)
  | "\(.key) holds the owner'"'"'s password: only postgres and migrate may"' <<<"$config")
for pair in "MONITOR_URL=https://status.$HOST" "MONITOR_HEARTBEAT_TOKEN=gate-heartbeat"; do
  key=${pair%%=*} want=${pair#*=}
  got=$(jq -r --arg k "$key" '.services.backup.environment[$k] // "(unset)"' <<<"$config")
  [[ "$got" == "$want" ]] || problem "backup $key is $got, wanted $want"
done
got=$(jq -r '.services.postgres.environment.POSTGRES_PASSWORD // "(unset)"' <<<"$config")
[[ "$got" == gate-postgres ]] || problem "postgres POSTGRES_PASSWORD is $got, wanted the deployment's"
got=$(jq -r '.services.centrifugo.environment.CENTRIFUGO_CLIENT_ALLOWED_ORIGINS // "(unset)"' <<<"$config")
[[ "$got" == "https://$HOST" ]] || problem "centrifugo accepts origins [$got], wanted https://$HOST alone"
jq -e '.services | has("mailpit") | not' <<<"$config" >/dev/null || problem "mailpit starts in a deployment: put it behind a profile"

caddy="$root/infra/edge/Caddyfile"
grep -qE '^[[:space:]]*header Strict-Transport-Security "max-age=31536000"$' "$caddy" \
  || problem "infra/edge/Caddyfile does not send exactly Strict-Transport-Security \"max-age=31536000\""
grep -E '^[[:space:]]*header Strict-Transport-Security' "$caddy" | grep -qiE 'includeSubDomains|preload' && problem "infra/edge/Caddyfile's Strict-Transport-Security reaches beyond the app host (includeSubDomains or preload)"
finish
