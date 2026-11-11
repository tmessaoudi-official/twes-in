#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every service that archives its write-ahead log runs under an init. pgBackRest's asynchronous archive-push leaves a
# child behind, which the container's PID 1 adopts; postgres as PID 1 took that child's failed exit for a crashed
# server process and cut every connection, at the first segment before the stanza existed and every 12 seconds while
# the bucket was unreachable, a shop's internet down. Under an init (`init: true`) the init reaps it and postgres only
# logs the failed push and keeps the segment. Read from a server's and a shop's merged configuration, every profile on.
# Usage: compose-init.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
declare -A installations=(
  [server]="compose.yaml compose.prod.yaml compose.backup.yaml compose.backup-bucket.yaml compose.deploy.yaml compose.release.yaml"
  [shop]="compose.yaml compose.prod.yaml compose.backup.yaml compose.backup-local.yaml compose.backup-offsite.yaml compose.onprem.yaml compose.release.yaml"
)
problems=() archiving=0
for installation in server shop; do
  args=()
  for layer in ${installations[$installation]}; do
    [[ -f "$root/$layer" ]] || { problems+=("$installation: found no $layer"); continue 2; }
    args+=(-f "$layer")
  done
  # env -i: the Makefile exports COMPOSE_FILE and COMPOSE_PROFILES, which would replace the files and profiles named here.
  config=$(cd "$root" && env -i PATH="$PATH" HOME="$HOME" docker compose "${args[@]}" --profile '*' config --no-interpolate --format json 2>&1) \
    || { problems+=("$installation: docker compose config failed: $config"); continue; }
  found=0
  while IFS=$'\t' read -r service init; do
    [[ -n "$service" ]] || continue
    found=$((found + 1)); archiving=$((archiving + 1))
    [[ "$init" == true ]] || problems+=("$installation: $service archives its WAL without an init: say \`init: true\`, or a failed push resets every connection")
  done < <(jq -r '.services | to_entries[]
    | select([(.value.command // []) | if type == "array" then .[] else . end | tostring | test("archive_command=")] | any)
    | [.key, (.value.init // false | tostring)] | @tsv' <<<"$config")
  ((found > 0)) || problems+=("$installation: no service archives its WAL: the check would pass over nothing")
done
if ((${#problems[@]})); then
  printf 'compose-init: FAIL — %d problem(s):\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
printf 'compose-init: OK — %d archiving service(s) over a server and a shop run under an init\n' "$archiving"
