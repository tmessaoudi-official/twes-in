#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# What deploying by digest promises (docs/START.md § 14): compose.release.yaml over the four deployment files makes every
# service that runs an image twes-in builds run the published image instead, by the digest it is given, and build
# nothing on the server. A service is on a built image when the deployment builds it, or runs the image another service
# builds (restore runs the database's); which digest it takes follows from what is built: infra/api the api's,
# infra/web the web's, infra/postgres the database's. A service added later is covered without touching this gate.
#   no build   none of them keeps a build, so a server that lacks the image pulls it rather than builds its checkout
#   digest     each runs exactly TWES_API_IMAGE, TWES_WEB_IMAGE or TWES_DATABASE_IMAGE, as its build says
#   required   the three have no default: a server missing one stops before starting anything
# The toolchain (the tools profile) is development's, never deployed, and left out.
# Usage: release-overlay.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
problems=()
problem() { problems+=("$1"); }
finish() {
  if ((${#problems[@]})); then
    printf 'release-overlay: FAIL — %d problem(s) with deploying by digest (docs/START.md § 14):\n' "${#problems[@]}"
    printf '  %s\n' "${problems[@]}"
    exit 1
  fi
  printf 'release-overlay: OK — %d services run a published image by its digest, none builds, %d images required\n' "$covered" "${#IMAGES[@]}"
  exit 0
}

HOST=gate.twes.invalid
declare -A IMAGES=([TWES_API_IMAGE]="ghcr.invalid/twes-in-api@sha256:$(printf '1%.0s' {1..64})"
  [TWES_WEB_IMAGE]="ghcr.invalid/twes-in-web@sha256:$(printf '2%.0s' {1..64})"
  [TWES_DATABASE_IMAGE]="ghcr.invalid/twes-in-database@sha256:$(printf '3%.0s' {1..64})")
# What the deployment's own files need to resolve, as made up as the host (scripts/gates/deploy-overlay.sh checks them).
values=(TWES_HOST=$HOST EDGE_TLS=gate@twes.invalid POSTGRES_PASSWORD=gate-postgres
  MAILER_DSN=smtp://relay.invalid:587 MAILER_SENDER=no-reply@$HOST APP_SECRET=s APP_MFA_KEY=k REALTIME_TOKEN_KEY=t
  REALTIME_API_KEY=a BACKUP_S3_ENDPOINT=e BACKUP_S3_BUCKET=b BACKUP_S3_REGION=r BACKUP_S3_KEY=k BACKUP_S3_SECRET=s
  BACKUP_DATABASE_PASSPHRASE=d BACKUP_FILES_PASSPHRASE=f MONITOR_TOKEN=m MONITOR_URL=https://status.$HOST
  MONITOR_HEARTBEAT_TOKEN=h SENTRY_DSN=https://gate@errors.$HOST/1)
for name in "${!IMAGES[@]}"; do values+=("$name=${IMAGES[$name]}"); done
deployment=(-f compose.yaml -f compose.prod.yaml -f compose.backup.yaml -f compose.deploy.yaml)
for f in compose.yaml compose.prod.yaml compose.backup.yaml compose.deploy.yaml compose.release.yaml; do
  [[ -f "$root/$f" ]] || problem "found no $f"
done
covered=0
((${#problems[@]})) && finish
env_file=$(mktemp); trap 'rm -f "$env_file"' EXIT
compose() {
  local files=("${deployment[@]}"); [[ "$1" == release ]] && files+=(-f compose.release.yaml); shift
  printf '%s\n' "$@" > "$env_file"
  (cd "$root" && env -i PATH="$PATH" HOME="$HOME" docker compose --env-file "$env_file" "${files[@]}" --profile '*' config --format json 2>&1)
}

built=$(compose deployment "${values[@]}") || { problem "docker compose config failed on the deployment: $built"; finish; }
released=$(compose release "${values[@]}") || { problem "docker compose config failed with compose.release.yaml: $released"; finish; }

# service<TAB>the variable its image must be, for every service on a built image.
expected=$(jq -r '
  def deployed: to_entries | map(select((.value.profiles // []) | index("tools") | not));
  def variable: if (.dockerfile // "") | test("infra/api/") then "TWES_API_IMAGE"
    elif (.dockerfile // "") | test("infra/web/") then "TWES_WEB_IMAGE"
    elif (.context // "") | test("/infra/postgres$") then "TWES_DATABASE_IMAGE" else "(unknown build)" end;
  .services | deployed as $all
  | ($all | map(select(.value.build) | {key: (.value.image // ""), value: (.value.build | variable)}) | map(select(.key != "")) | from_entries) as $made
  | $all[] | select(.value.build or $made[.value.image // ""])
  | "\(.key)\t\(if .value.build then (.value.build | variable) else $made[.value.image] end)"' <<<"$built")
[[ -n "$expected" ]] || problem "the deployment builds nothing: the gate no longer reads it"

while IFS=$'\t' read -r service variable; do
  [[ -n "$service" ]] || continue
  covered=$((covered+1))
  if [[ "$variable" == "(unknown build)" ]]; then problem "$service builds something this gate cannot name: say which published image it is"; continue; fi
  jq -e --arg s "$service" '.services[$s].build' <<<"$released" >/dev/null && problem "$service still builds: clear it with build: !reset null"
  got=$(jq -r --arg s "$service" '.services[$s].image // "(none)"' <<<"$released")
  [[ "$got" == "${IMAGES[$variable]}" ]] || problem "$service runs $got, wanted \${$variable}"
done <<<"$expected"

# Without each image the configuration must refuse to resolve, naming it.
for name in "${!IMAGES[@]}"; do
  without=()
  for value in "${values[@]}"; do [[ "$value" == "$name="* ]] || without+=("$value"); done
  out=$(compose release "${without[@]}")
  if [[ $? -eq 0 || "$out" != *"$name"* ]]; then problem "$name is not required: a server without it would run something else"; fi
done
finish
