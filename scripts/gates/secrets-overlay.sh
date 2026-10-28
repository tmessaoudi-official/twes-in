#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every secret of a deployment reaches its container as a compose secret, a file under /run/secrets, never as its
# environment, where `docker inspect` and `docker compose config` show it (docs/SPEC.md § 7, Docker secrets). Read from
# each installation's files merged as scripts/deploy/deploy.sh merges them, and the production stack CI boots:
#   values     no value the installation was given appears anywhere in its merged configuration
#   names      no service's environment gives a secret a value, compose.yaml's development values included
#   sources    every secret comes from a variable of the values file, by its name
#   readers    every secret a service mounts is read: the api image reads them all (ContainerSecrets), postgres through
#              POSTGRES_PASSWORD_FILE, the others through infra/postgres/twes-secrets as their entrypoint, or a <NAME>_FILE,
#              each at a name its tool reads
#   workers    no api-image service's environment holds a variable its secrets set, not even empty, nor a database
#              address: FrankenPHP's workers read the environment over what ContainerSecrets set at boot
#   database   the api image's addresses are templates carrying the passwords as placeholders, and the owner's reaches
#              postgres and migrate only
#   aliases    no `!reset` or `!override` sits in a block an alias or a merge reuses: Compose 2.38, CI's runners, drops
#              the tag there, and the workers kept the secrets' variables CI exports
#   rotation   every service mounting a secret carries the label twes.secrets-digest, the digest of the values that
#              scripts/deploy/deploy.sh hands compose: Compose keeps a running container whose secret's value alone
#              changed, so without it a rotated key never reaches the container
#   mounts     every service mounting a file from the checkout carries the label twes.mounts-digest, the digest of those
#              files that deploy.sh, `make up` and the monitor's start hand compose: a checkout writes the file anew, the
#              mount keeps the old one, and Compose keeps the container (a server's, a shop's, development's, the monitor's)
# Usage: secrets-overlay.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
problems=()
problem() { problems+=("$1"); }

# What a service may never hold in its environment, whichever variable a file gives it.
SECRET_NAMES=(APP_SECRET APP_MFA_KEY REALTIME_TOKEN_KEY REALTIME_API_KEY POSTGRES_PASSWORD POSTGRES_APP_PASSWORD MAILER_DSN
  MONITOR_TOKEN MONITOR_HEARTBEAT_TOKEN BACKUP_DATABASE_PASSPHRASE BACKUP_FILES_PASSPHRASE BACKUP_S3_SECRET
  PGBACKREST_REPO1_CIPHER_PASS PGBACKREST_REPO1_S3_KEY_SECRET RESTIC_PASSWORD AWS_SECRET_ACCESS_KEY
  CENTRIFUGO_CLIENT_TOKEN_HMAC_SECRET_KEY CENTRIFUGO_HTTP_API_KEY)
# compose.yaml's development values, which a production container must never be handed.
DEVELOPMENT=(ojCIqjd0KzQ4JrX9wyEpy58LdtCAQ56MTwM8CWA0XOc= twes-in-development-realtime-token-key-0001
  twes-in-development-realtime-api-key twes_app:twes-app@ twes:twes@)
API_IMAGE=(api worker demo-worker migrate)
# Where the other services' readers take a secret from: the variable each tool reads, as a file under /run/secrets.
READ_TARGETS=(/run/secrets/postgres_password /run/secrets/pgbackrest_repo1_cipher_pass /run/secrets/pgbackrest_repo1_s3_key_secret
  /run/secrets/restic_password /run/secrets/aws_secret_access_key /run/secrets/monitor_heartbeat_token
  /run/secrets/centrifugo_client_token_hmac_secret_key /run/secrets/centrifugo_http_api_key)
# What every api-image service must mount: a missing one falls back to api/.env's development value, or to nothing.
API_SECRETS=(app_secret app_mfa_key realtime_token_key realtime_api_key postgres_app_password mailer_dsn)

# Each secret's value made up and unlike any other, so finding it anywhere is the finding.
secrets=(APP_SECRET APP_MFA_KEY REALTIME_TOKEN_KEY REALTIME_API_KEY POSTGRES_PASSWORD POSTGRES_APP_PASSWORD MAILER_DSN
  MONITOR_TOKEN MONITOR_HEARTBEAT_TOKEN BACKUP_DATABASE_PASSPHRASE BACKUP_FILES_PASSPHRASE BACKUP_S3_SECRET)
values=()
for name in "${secrets[@]}"; do values+=("$name=GateValue${name//_/}"); done
facts=(TWES_HOST=gate.invalid EDGE_TLS=gate@gate.invalid MAILER_SENDER=gate@gate.invalid MONITOR_URL=https://monitor.invalid
  SENTRY_DSN=https://key@monitor.invalid/1 BACKUP_S3_ENDPOINT=s3.invalid BACKUP_S3_BUCKET=b BACKUP_S3_REGION=r
  BACKUP_S3_KEY=GateAccessKeyId LAN_HOST=192.0.2.10 TWES_API_IMAGE=r.invalid/twes-in-api@sha256:0
  TWES_WEB_IMAGE=r.invalid/twes-in-web@sha256:0 TWES_DATABASE_IMAGE=r.invalid/twes-in-database@sha256:0
  TWES_SECRETS_DIGEST=GateSecretsDigest)

declare -A installations=(
  [production]="compose.yaml compose.prod.yaml"
  [drill]="compose.yaml compose.prod.yaml compose.backup.yaml compose.backup-bucket.yaml"
  [server]="compose.yaml compose.prod.yaml compose.backup.yaml compose.backup-bucket.yaml compose.deploy.yaml compose.release.yaml"
  [shop]="compose.yaml compose.prod.yaml compose.backup.yaml compose.backup-local.yaml compose.onprem.yaml compose.release.yaml"
  [shop-offsite]="compose.yaml compose.prod.yaml compose.backup.yaml compose.backup-local.yaml compose.backup-offsite.yaml compose.onprem.yaml compose.release.yaml"
)
env_file=$(mktemp); trap 'rm -f "$env_file"' EXIT
printf '%s\n' "${values[@]}" "${facts[@]}" > "$env_file"
checked=0
for installation in production drill server shop shop-offsite; do
  args=()
  for layer in ${installations[$installation]}; do
    [[ -f "$root/$layer" ]] || { problem "$installation: found no $layer"; continue 2; }
    args+=(-f "$layer")
  done
  # The profiles that start nothing by default (restore) are rendered too: a restore reads the same secrets.
  config=$(cd "$root" && env -i PATH="$PATH" HOME="$HOME" docker compose --env-file "$env_file" "${args[@]}" --profile restore config --format json 2>&1) \
    || { problem "$installation: docker compose config failed: $config"; continue; }
  checked=$((checked + 1))
  for value in "${values[@]}"; do
    [[ "$config" != *"${value#*=}"* ]] || problem "$installation: the value of ${value%%=*} is written in the configuration"
  done
  for literal in "${DEVELOPMENT[@]}"; do
    [[ "$config" != *"$literal"* ]] || problem "$installation: compose.yaml's development value $literal reaches a container"
  done
  while IFS= read -r line; do [[ -n "$line" ]] && problem "$installation: $line"; done < <(jq -r --argjson names "$(printf '%s\n' "${SECRET_NAMES[@]}" | jq -R . | jq -s .)" '
    .services | to_entries[] | .key as $s | (.value.environment // {}) as $environment | $environment | keys[]
    | select(. as $k | $names | index($k))
    # Emptied, it carries nothing here (the api image is held to more below).
    | select($environment[.] != "")
    | "\($s) holds \(.) in its environment: hand it as a compose secret"' <<<"$config")
  # FrankenPHP's worker runner gives each request what was set at boot only beneath its own environment, so a variable
  # an api-image service's secret sets must not be in its environment at all, nor an address its template makes.
  while IFS= read -r line; do [[ -n "$line" ]] && problem "$installation: $line"; done < <(jq -r --argjson api "$(printf '%s\n' "${API_IMAGE[@]}" | jq -R . | jq -s .)" '
    .services | to_entries[] | .key as $s | select($api | index($s)) | (.value.environment // {}) as $environment
    | ([(.value.secrets // [])[] | .target | split("/") | last | ascii_upcase]
       + ([$environment | keys[] | select(endswith("_TEMPLATE")) | .[:-9]])) as $set
    | $environment | keys[] | select(. as $k | $set | index($k))
    | "\($s) holds \(.) in its environment beside the secret or template that sets it: the workers would read the environment"' <<<"$config")
  while IFS= read -r line; do [[ -n "$line" ]] && problem "$installation: $line"; done < <(jq -r --argjson given "$(printf '%s\n' "${secrets[@]}" | jq -R . | jq -s .)" '
    (.secrets // {}) | to_entries[] | select((.value.environment // "") as $e | $given | index($e) | not)
    | "the secret \(.key) comes from \(.value.environment // .value.file // "nowhere"), not a variable of the values file"' <<<"$config")
  # The shim turns any file into the variable its name says, so a target misnamed is read by no tool: each target is a
  # name its reader takes (pgBackRest, restic through RESTIC_PASSWORD_FILE, Centrifugo, twes-backup's heartbeats,
  # PostgreSQL through POSTGRES_PASSWORD_FILE), or the api image's, which reads each by its secret's own name.
  while IFS= read -r line; do [[ -n "$line" ]] && problem "$installation: $line"; done < <(jq -r --argjson api "$(printf '%s\n' "${API_IMAGE[@]}" | jq -R . | jq -s .)" --argjson read "$(printf '%s\n' "${READ_TARGETS[@]}" | jq -R . | jq -s .)" '
    .services | to_entries[] | .key as $s | select(($api | index($s)) | not)
    | (.value.secrets // [])[] | select(.target as $t | $read | index($t) | not)
    | "\($s) mounts \(.source) at \(.target), a name no tool there reads"' <<<"$config")
  # A secret mounted but read by nothing is a value the container goes without.
  while IFS= read -r line; do [[ -n "$line" ]] && problem "$installation: $line"; done < <(jq -r --argjson api "$(printf '%s\n' "${API_IMAGE[@]}" | jq -R . | jq -s .)" '
    .services | to_entries[] | .key as $s | .value as $v
    | select(($api | index($s)) | not)
    | (($v.entrypoint // []) | (.[0] == "twes-secrets" or (.[0] == "sh" and .[1] == "/twes-secrets"))) as $shim
    | ($v.environment // {}) as $env
    | ($v.secrets // [])[] | .target as $t
    | select($shim | not)
    | select([$env | to_entries[] | select(.key | endswith("_FILE")) | .value] | index($t) | not)
    | "\($s) mounts \($t) and nothing reads it: run it through twes-secrets or point a <NAME>_FILE at it"' <<<"$config")
  while IFS= read -r line; do [[ -n "$line" ]] && problem "$installation: $line"; done < <(jq -r '
    .services | to_entries[] | select((.value.secrets // []) | length > 0)
    | select((.value.labels // {})["twes.secrets-digest"] != "GateSecretsDigest")
    | "\(.key) mounts a secret without the label twes.secrets-digest: ${TWES_SECRETS_DIGEST:-}: a rotated secret would never reach it"' <<<"$config")
  for service in "${API_IMAGE[@]}"; do
    jq -e --arg s "$service" '.services | has($s)' <<<"$config" >/dev/null || continue
    mounted=$(jq -r --arg s "$service" '[(.services[$s].secrets // [])[] | .source] | join(" ")' <<<"$config")
    wanted=("${API_SECRETS[@]}")
    [[ "$service" == migrate ]] && wanted+=(postgres_password)
    for secret in "${wanted[@]}"; do
      [[ " $mounted " == *" $secret "* ]] || problem "$installation: $service does not mount the secret $secret"
    done
    url=$(jq -r --arg s "$service" '.services[$s].environment.DATABASE_URL_TEMPLATE // ""' <<<"$config")
    [[ "$url" == "postgresql://twes_app:{postgres_app_password}@"* ]] || problem "$installation: $service DATABASE_URL_TEMPLATE is not the application's role with its placeholder: $url"
  done
  url=$(jq -r '.services.migrate.environment.DATABASE_MIGRATION_URL_TEMPLATE // ""' <<<"$config")
  [[ "$url" == "postgresql://twes:{postgres_password}@"* ]] || problem "$installation: migrate DATABASE_MIGRATION_URL_TEMPLATE is not the owner with its placeholder: $url"
  while IFS= read -r line; do [[ -n "$line" ]] && problem "$installation: $line"; done < <(jq -r '.services | to_entries[]
    | select(.key != "postgres" and .key != "migrate")
    | select([(.value.secrets // [])[] | .source] | index("postgres_password"))
    | "\(.key) mounts the owner'"'"'s password: only postgres and migrate may"' <<<"$config")
done

# mounts: every file a service mounts from the checkout reaches its running container after an update. A checkout
# writes such a file anew; the container's mount keeps the one it started with, and Compose, finding the configuration
# unchanged, keeps the container: an edge served the previous Caddyfile, centrifugo the previous shim. Every service
# mounting a file carries the label twes.mounts-digest: ${TWES_MOUNTS_DIGEST:-}, the digest scripts/deploy/mounts-digest.sh
# makes of those files, which deploy.sh, `make up` and the monitor's start hand Compose. A directory's mount sees what
# changes inside it and needs none. Read without interpolation, every profile on, from every installation that mounts
# files: a server, a shop, development live and from the images, and the monitor. PostgreSQL runs
# /docker-entrypoint-initdb.d only on an empty volume, so a later init.sql changes nothing and is left out.
MOUNTS_LABEL='${TWES_MOUNTS_DIGEST:-}'
declare -A mounting=(
  [server]="compose.yaml compose.prod.yaml compose.backup.yaml compose.backup-bucket.yaml compose.deploy.yaml compose.release.yaml"
  [shop]="compose.yaml compose.prod.yaml compose.backup.yaml compose.backup-local.yaml compose.backup-offsite.yaml compose.onprem.yaml compose.release.yaml"
  [development]="compose.yaml compose.live.yaml"
  [images]="compose.yaml"
  [monitor]="infra/monitor/compose.yaml"
)
mounted=0
for installation in server shop development images monitor; do
  args=()
  for layer in ${mounting[$installation]}; do
    [[ -f "$root/$layer" ]] || { problem "$installation: found no $layer"; continue 2; }
    args+=(-f "$layer")
  done
  config=$(cd "$root" && env -i PATH="$PATH" HOME="$HOME" docker compose "${args[@]}" --profile '*' config --no-interpolate --format json 2>&1) \
    || { problem "$installation: docker compose config failed: $config"; continue; }
  while IFS=$'\t' read -r service source target label; do
    [[ -n "$service" && -f "$source" && "$target" != /docker-entrypoint-initdb.d/* ]] || continue
    mounted=$((mounted + 1))
    [[ "$label" == "$MOUNTS_LABEL" ]] \
      || problem "$installation: $service mounts ${source#"$root"/} without the label twes.mounts-digest: $MOUNTS_LABEL: an update would leave it the old file"
  done < <(jq -r '.services | to_entries[] | .key as $service | ((.value.labels // {})["twes.mounts-digest"] // "") as $label
    | (.value.volumes // [])[] | select(.type == "bind") | [$service, .source, .target, $label] | @tsv' <<<"$config")
done
((mounted > 0)) || problem "found no file mounted from the checkout in any installation: the mounts check would pass over nothing"

# Read from the files themselves, so the Compose that runs this gate cannot hide it by honouring the tag.
while IFS= read -r line; do [[ -n "$line" ]] && problem "$line"; done < <(cd "$root" && python3 -I - compose*.yaml <<'PY'
import re, sys
for path in sys.argv[1:]:
    lines = open(path, encoding='utf-8').read().split('\n')
    text = '\n'.join(lines)
    for i, line in enumerate(lines):
        anchor = re.search(r'&([A-Za-z0-9_-]+)', line.split('#')[0])
        if not anchor or not re.search(r'\*' + re.escape(anchor.group(1)) + r'\b', text):
            continue
        indent = len(line) - len(line.lstrip())
        block = [line]
        for following in lines[i + 1:]:
            if following.strip() and len(following) - len(following.lstrip()) <= indent:
                break
            block.append(following)
        if any(re.search(r'!(reset|override)\b', b.split('#')[0]) for b in block):
            print(f'{path}:{i + 1} &{anchor.group(1)} carries !reset or !override to an alias, which Compose 2.38 drops: write it in each service')
PY
)

if ((${#problems[@]})); then
  printf 'secrets-overlay: FAIL — %d problem(s) with how secrets reach the containers (docs/SPEC.md § 7, Docker secrets):\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
printf 'secrets-overlay: OK — %d installations hand their %d secrets as compose secrets, each read, none in an environment; %d file mounts carry twes.mounts-digest\n' "$checked" "${#secrets[@]}" "$mounted"
