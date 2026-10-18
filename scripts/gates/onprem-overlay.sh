#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# What a shop's installation promises (docs/START.md § 16), read from its files merged as scripts/deploy/deploy.sh
# merges them for TWES_INSTALL=onprem: compose.yaml, compose.prod.yaml, compose.backup.yaml, compose.backup-local.yaml
# and compose.onprem.yaml, and with compose.backup-offsite.yaml once the off-site copy is on.
#   ports      only the edge publishes, on 80 and 443: the shop's network reaches nothing else, the database least of all
#   addresses  passkeys are bound to localhost, the one name the shop's computer has; mailed links, the phone's pairing
#              and the realtime origins name the network address LAN_HOST, which may change between two starts
#   database   api and both workers connect as the application's role; the owner's password reaches postgres and migrate only
#   required   LAN_HOST, the two database passwords, the mail account, the deep health's token and both backup
#              passphrases have no default; the off-site copy, once on, requires every bucket value
#   monitor    optional: SENTRY_DSN and MONITOR_URL may be left empty, and the installation still starts
#   mailpit    behind a profile, so it never starts
#   edge       infra/onprem/Caddyfile answers on https://localhost and the network address, from its own authority
# Usage: onprem-overlay.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
problems=()
problem() { problems+=("$1"); }
finish() {
  if ((${#problems[@]})); then
    printf 'onprem-overlay: FAIL — %d problem(s) with a shop'"'"'s installation (docs/START.md § 16):\n' "${#problems[@]}"
    printf '  %s\n' "${problems[@]}"
    exit 1
  fi
  printf 'onprem-overlay: OK — only the edge publishes, passkeys on localhost, links on the network address, %d settings required, the monitor optional\n' "${#REQUIRED[@]}"
  exit 0
}

REQUIRED=(LAN_HOST POSTGRES_PASSWORD POSTGRES_APP_PASSWORD MAILER_DSN MAILER_SENDER MONITOR_TOKEN BACKUP_DATABASE_PASSPHRASE BACKUP_FILES_PASSPHRASE)
OFFSITE_REQUIRED=(BACKUP_S3_ENDPOINT BACKUP_S3_BUCKET BACKUP_S3_REGION BACKUP_S3_KEY BACKUP_S3_SECRET)
LAN=192.0.2.10
# Every value the files need, made up, given as deploy.sh gives them: in an --env-file, with nothing of the developer's
# own environment. The monitor's are left out on purpose: a shop may have none.
values=(LAN_HOST=$LAN POSTGRES_PASSWORD=gate-postgres POSTGRES_APP_PASSWORD=gate-app
  MAILER_DSN=smtp://shop%40gmail.invalid:app@smtp.gmail.invalid:587 MAILER_SENDER=shop@gmail.invalid APP_SECRET=s APP_MFA_KEY=k
  REALTIME_TOKEN_KEY=t REALTIME_API_KEY=a BACKUP_DATABASE_PASSPHRASE=d BACKUP_FILES_PASSPHRASE=f MONITOR_TOKEN=gate-monitor)
bucket=(BACKUP_S3_ENDPOINT=e BACKUP_S3_BUCKET=b BACKUP_S3_REGION=r BACKUP_S3_KEY=k BACKUP_S3_SECRET=s)
shop=(compose.yaml compose.prod.yaml compose.backup.yaml compose.backup-local.yaml compose.onprem.yaml)
offsite=(compose.yaml compose.prod.yaml compose.backup.yaml compose.backup-local.yaml compose.backup-offsite.yaml compose.onprem.yaml)
for f in "${offsite[@]}" infra/onprem/Caddyfile; do
  [[ -f "$root/$f" ]] || problem "found no $f"
done
((${#problems[@]})) && finish
env_file=$(mktemp); trap 'rm -f "$env_file"' EXIT
compose() { # layers (a name of an array), then the values
  local -n layers=$1; shift
  local args=() layer
  for layer in "${layers[@]}"; do args+=(-f "$layer"); done
  printf '%s\n' "$@" > "$env_file"
  (cd "$root" && env -i PATH="$PATH" HOME="$HOME" docker compose --env-file "$env_file" "${args[@]}" config --format json 2>&1)
}
without() { # name, then values: the values without that name
  local name=$1 value; shift
  for value in "$@"; do [[ "$value" == "$name="* ]] || printf '%s\n' "$value"; done
}

config=$(compose shop "${values[@]}") || { problem "docker compose config failed: $config"; finish; }

for name in "${REQUIRED[@]}"; do
  mapfile -t rest < <(without "$name" "${values[@]}")
  out=$(compose shop "${rest[@]}")
  if [[ $? -eq 0 || "$out" != *"$name"* ]]; then problem "$name is not required: a shop without it would start on a default"; fi
done
# The configuration above resolved without the monitor's values; none may then carry one the shop never gave.
for name in SENTRY_DSN MONITOR_URL MONITOR_HEARTBEAT_TOKEN; do
  [[ "$(jq -r --arg k "$name" '[.services[] | .environment // {} | .[$k] // empty | select(. != "")] | length' <<<"$config")" == 0 ]] \
    || problem "$name has a value no shop gave it"
  grep -qE "\\\$\\{$name:\\?" "$root/compose.onprem.yaml" && problem "compose.onprem.yaml requires $name: the monitor is optional on premises"
done

offsite_config=$(compose offsite "${values[@]}" "${bucket[@]}") || problem "docker compose config failed with the off-site copy on: $offsite_config"
for name in "${OFFSITE_REQUIRED[@]}"; do
  mapfile -t rest < <(without "$name" "${values[@]}" "${bucket[@]}")
  out=$(compose offsite "${rest[@]}")
  if [[ $? -eq 0 || "$out" != *"$name"* ]]; then problem "$name is not required once the off-site copy is on: it would copy nowhere"; fi
done

while IFS= read -r line; do [[ -n "$line" ]] && problem "$line"; done < <(jq -r '.services | to_entries[]
  | select(.key != "edge" and ((.value.ports // []) | length) > 0)
  | "\(.key) publishes \([.value.ports[] | "\(.published // "?"):\(.target)"] | join(", ")): only the edge may"' <<<"$config")
edge_ports=$(jq -r '[.services.edge.ports // [] | .[] | "\(.published):\(.target)/\(.protocol // "tcp")"] | sort | join(" ")' <<<"$config")
[[ "$edge_ports" == "443:443/tcp 80:80/tcp" ]] || problem "the edge publishes [$edge_ports], wanted 80 and 443"

for service in api worker demo-worker migrate; do
  for pair in "DEFAULT_URI=https://$LAN" "APP_WEBAUTHN_RP_ID=localhost" "APP_WEBAUTHN_ORIGINS=https://localhost" \
    "PAIRING_ORIGIN=https://$LAN" "MAILER_DSN=smtp://shop%40gmail.invalid:app@smtp.gmail.invalid:587" \
    "MAILER_SENDER=shop@gmail.invalid" "MONITOR_TOKEN=gate-monitor" "APP_ENV=prod"; do
    key=${pair%%=*} want=${pair#*=}
    got=$(jq -r --arg s "$service" --arg k "$key" '.services[$s].environment[$k] // "(unset)"' <<<"$config")
    [[ "$got" == "$want" ]] || problem "$service $key is $got, wanted $want"
  done
done
for service in api worker demo-worker; do
  got=$(jq -r --arg s "$service" '.services[$s].environment.DATABASE_URL // ""' <<<"$config")
  [[ "$got" == "postgresql://twes_app:gate-app@"* ]] || problem "$service DATABASE_URL is not the application's role with POSTGRES_APP_PASSWORD: $got"
done
got=$(jq -r '.services.migrate.environment.DATABASE_MIGRATION_URL // ""' <<<"$config")
[[ "$got" == "postgresql://twes:gate-postgres@"* ]] || problem "migrate DATABASE_MIGRATION_URL is not the owner with POSTGRES_PASSWORD: $got"
while IFS= read -r line; do [[ -n "$line" ]] && problem "$line"; done < <(jq -r '.services | to_entries[]
  | select(.key != "postgres" and .key != "migrate")
  | select([.value.environment // {} | .[] | tostring | contains("gate-postgres")] | any)
  | "\(.key) holds the owner'"'"'s password: only postgres and migrate may"' <<<"$config")
got=$(jq -r '.services.centrifugo.environment.CENTRIFUGO_CLIENT_ALLOWED_ORIGINS // "(unset)"' <<<"$config")
[[ "$got" == "https://localhost https://$LAN" ]] || problem "centrifugo accepts origins [$got], wanted https://localhost https://$LAN"
jq -e '.services | has("mailpit") | not' <<<"$config" >/dev/null || problem "mailpit starts in a shop's installation: put it behind a profile"
jq -e '.services | has("lan") | not' <<<"$config" >/dev/null || problem "development's phone door starts in a shop's installation: the edge is the door"

caddy="$root/infra/onprem/Caddyfile"
grep -qE '^https://localhost, https://\{\$LAN_HOST\} \{$' "$caddy" || problem "infra/onprem/Caddyfile does not answer on https://localhost and https://{\$LAN_HOST}"
grep -qE '^[[:space:]]*tls internal$' "$caddy" || problem "infra/onprem/Caddyfile does not take its certificates from its own authority (tls internal)"
grep -qE '^[[:space:]]*@root path /root.crt$' "$caddy" || problem "infra/onprem/Caddyfile does not hand out its root certificate at /root.crt"
finish
