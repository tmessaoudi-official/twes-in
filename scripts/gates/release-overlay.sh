#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# What deploying by digest promises (docs/START.md § 14): compose.release.yaml over the five deployment files makes every
# service that runs an image twes-in builds run the published image instead, by the digest it is given, and build
# nothing on the server. A service is on a built image when the deployment builds it, or runs the image another service
# builds (restore runs the database's); which digest it takes follows from what is built: infra/api the api's,
# infra/web the web's, infra/postgres the database's. A service added later is covered without touching this gate.
#   no build   none of them keeps a build, so a server that lacks the image pulls it rather than builds its checkout
#   digest     each runs exactly TWES_API_IMAGE, TWES_WEB_IMAGE or TWES_DATABASE_IMAGE, as its build says
#   required   the three have no default: a server missing one stops before starting anything
#   pinned     every other service a server or a shop starts (merged as scripts/deploy/deploy.sh merges each, the shop
#              with its off-site copy on) runs a third-party image named by tag AND index digest,
#              <name>:<tag>@sha256:<64 hex>: a tag is resolved on the machine at pull time and the publisher may move it,
#              while the signed release tag fixes the commit, and so these digests with it (docs/UPDATE.md)
# The toolchain (the tools profile) is development's, never deployed, and left out; so is any service behind a profile,
# which neither installation starts (mailpit, the development LAN door).
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
  printf 'release-overlay: OK — %d services run a published image by its digest, none builds, %d images required, %d third-party services started by tag and digest\n' "$covered" "${#IMAGES[@]}" "$pinned"
  exit 0
}

HOST=gate.twes.invalid
declare -A IMAGES=([TWES_API_IMAGE]="ghcr.invalid/twes-in-api@sha256:$(printf '1%.0s' {1..64})"
  [TWES_WEB_IMAGE]="ghcr.invalid/twes-in-web@sha256:$(printf '2%.0s' {1..64})"
  [TWES_DATABASE_IMAGE]="ghcr.invalid/twes-in-database@sha256:$(printf '3%.0s' {1..64})")
# What the deployment's own files need to resolve, as made up as the host (scripts/gates/deploy-overlay.sh checks them).
values=(TWES_HOST=$HOST EDGE_TLS=gate@twes.invalid POSTGRES_PASSWORD=gate-postgres POSTGRES_APP_PASSWORD=gate-app
  MAILER_DSN=smtp://relay.invalid:587 MAILER_SENDER=no-reply@$HOST APP_SECRET=s APP_MFA_KEY=k REALTIME_TOKEN_KEY=t
  REALTIME_API_KEY=a BACKUP_S3_ENDPOINT=e BACKUP_S3_BUCKET=b BACKUP_S3_REGION=r BACKUP_S3_KEY=k BACKUP_S3_SECRET=s
  BACKUP_DATABASE_PASSPHRASE=d BACKUP_FILES_PASSPHRASE=f MONITOR_TOKEN=m MONITOR_URL=https://status.$HOST
  MONITOR_HEARTBEAT_TOKEN=h SENTRY_DSN=https://gate@errors.$HOST/1)
for name in "${!IMAGES[@]}"; do values+=("$name=${IMAGES[$name]}"); done
deployment=(-f compose.yaml -f compose.prod.yaml -f compose.backup.yaml -f compose.backup-bucket.yaml -f compose.deploy.yaml)
# A shop's files, as deploy.sh layers them for TWES_INSTALL=onprem with BACKUP_OFFSITE=on (the larger of its two sets).
shop=(-f compose.yaml -f compose.prod.yaml -f compose.backup.yaml -f compose.backup-local.yaml -f compose.backup-offsite.yaml -f compose.onprem.yaml)
for f in compose.yaml compose.prod.yaml compose.backup.yaml compose.backup-bucket.yaml compose.deploy.yaml compose.release.yaml \
  compose.backup-local.yaml compose.backup-offsite.yaml compose.onprem.yaml; do
  [[ -f "$root/$f" ]] || problem "found no $f"
done
covered=0 pinned=0
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

# What each installation starts, the release overlay on and no profile asked for: every service not on one of the three
# published images runs a third-party one, which must be named by tag and digest.
ours=$(printf '%s\n' "${IMAGES[@]}" | jq -Rsc 'split("\n") | map(select(. != ""))')
for installation in server shop; do
  if [[ "$installation" == server ]]; then files=("${deployment[@]}"); else files=("${shop[@]}"); fi
  printf '%s\n' "${values[@]}" LAN_HOST=192.0.2.10 > "$env_file"
  started=$(cd "$root" && env -i PATH="$PATH" HOME="$HOME" docker compose --env-file "$env_file" "${files[@]}" -f compose.release.yaml config --format json 2>&1) \
    || { problem "docker compose config failed on the $installation's files with compose.release.yaml: $started"; continue; }
  while IFS=$'\t' read -r service image; do
    [[ -n "$service" ]] || continue
    pinned=$((pinned+1))
    [[ "$image" =~ ^[^@[:space:]]+:[A-Za-z0-9_.-]+@sha256:[0-9a-f]{64}$ ]] \
      || problem "the $installation starts $service on $image: name it by tag and index digest, <name>:<tag>@sha256:<64 hex> (docs/UPDATE.md)"
  done < <(jq -r --argjson ours "$ours" '.services | to_entries[]
    | select((.value.image // "") as $i | $ours | index($i) | not) | "\(.key)\t\(.value.image // "(no image)")"' <<<"$started")
done
finish
