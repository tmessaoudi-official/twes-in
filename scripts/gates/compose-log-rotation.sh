#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Every service in compose.yaml has its output rotated by Docker's json-file driver, 10 MB × 5 files (docs/SPEC.md
# § 7, 2026-09-17): a service added without it grows its log until the disk is full. Reads the resolved
# configuration, so an anchor, an override or an extension counts as what Docker will run.
# Usage: compose-log-rotation.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
# Every profile on: a service behind one (lan, started by make up) is a service too.
config=$(cd "$root" && docker compose --profile "*" config --format json 2>&1) || { printf 'compose-log-rotation: FAIL — docker compose config failed:\n%s\n' "$config"; exit 1; }
total=$(jq '.services | length' <<<"$config")
mapfile -t wrong < <(jq -r '.services | to_entries[]
  | select(.value.logging.driver != "json-file" or .value.logging.options["max-size"] != "10m" or .value.logging.options["max-file"] != "5")
  | .key' <<<"$config")
if ((${#wrong[@]})); then
  printf 'compose-log-rotation: FAIL — %d service(s) without json-file rotation at 10m × 5 (fix: logging: *logging):\n' "${#wrong[@]}"
  printf '  %s\n' "${wrong[@]}"
  exit 1
fi
printf 'compose-log-rotation: OK — %d services rotate their logs\n' "$total"
