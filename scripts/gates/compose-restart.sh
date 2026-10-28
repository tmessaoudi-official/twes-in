#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every service of every installation says what Docker does when it stops: `unless-stopped` for one that runs, so a
# crash or a reboot brings it back, or `"no"` written out for one that does a job and exits (the migrations, the
# toolchain, a restore). Development's api died one evening of a PHP fault and stayed down, while its workers came back
# by themselves; a service left without the key is that, found before it happens. Read from each installation's merged
# configuration, every profile on: a server, a shop, development live and from the images, and the monitor.
# Usage: compose-restart.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
declare -A installations=(
  [server]="compose.yaml compose.prod.yaml compose.backup.yaml compose.backup-bucket.yaml compose.deploy.yaml compose.release.yaml"
  [shop]="compose.yaml compose.prod.yaml compose.backup.yaml compose.backup-local.yaml compose.backup-offsite.yaml compose.onprem.yaml compose.release.yaml"
  [development]="compose.yaml compose.live.yaml"
  [images]="compose.yaml"
  [monitor]="infra/monitor/compose.yaml"
)
problems=() services=0
for installation in server shop development images monitor; do
  args=()
  for layer in ${installations[$installation]}; do
    [[ -f "$root/$layer" ]] || { problems+=("$installation: found no $layer"); continue 2; }
    args+=(-f "$layer")
  done
  # env -i: the Makefile exports COMPOSE_FILE and COMPOSE_PROFILES, which would replace the files and profiles named here.
  config=$(cd "$root" && env -i PATH="$PATH" HOME="$HOME" docker compose "${args[@]}" --profile '*' config --no-interpolate --format json 2>&1) \
    || { problems+=("$installation: docker compose config failed: $config"); continue; }
  while IFS=$'\t' read -r service restart; do
    [[ -n "$service" ]] || continue
    services=$((services + 1))
    case "$restart" in
      unless-stopped | no) ;;
      '') problems+=("$installation: $service says nothing of a restart: unless-stopped if it runs, \"no\" if it does a job and exits") ;;
      *) problems+=("$installation: $service restarts « $restart »: unless-stopped if it runs, \"no\" if it does a job and exits") ;;
    esac
  done < <(jq -r '.services | to_entries[] | [.key, (.value.restart // "")] | @tsv' <<<"$config")
done
((services > 0)) || problems+=("read no service in any installation: the check would pass over nothing")
if ((${#problems[@]})); then
  printf 'compose-restart: FAIL — %d service(s) whose restart is not said:\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
printf 'compose-restart: OK — %d services over 5 installations say whether Docker starts them again\n' "$services"
