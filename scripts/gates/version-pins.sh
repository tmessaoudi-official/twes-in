#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Several versions are written in more than one file, and a bump that moves only one copy builds one thing locally and
# tests another in CI (docs/UPDATE.md lists every pin and its copies; docs/SPEC.md § 7, 2026-09-19). This gate
# refuses any copy that disagrees, and any copy that disappeared: a pin found nowhere is a failure, never a skip.
#   postgres image         compose.yaml (the one copy: CI runs the compose service, never a pin of its own)
#   serverVersion          every DATABASE_URL (compose.yaml, api/.env*) = the postgres image's major
#   PHP                    infra/api/Dockerfile's frankenphp tag = composer.json's floor
#   Node                   web/.nvmrc = infra/web/Dockerfile's node image = web/package.json engines; and the
#                          tools image (infra/web/tools.Dockerfile) runs the same full version
#   Playwright             web/package-lock.json's @playwright/test = infra/web/tools.Dockerfile's ARG
#   Symfony minor          every symfony/* pinned "X.Y.*" = composer.json extra.symfony.require
#   Angular major          every @angular/* in web/package.json = @angular/core's
# Usage: version-pins.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
problems=()
problem() { problems+=("$1"); }
# grab FILE ERE: every match of ERE in FILE, one per line (nothing when the file is absent).
grab() { [[ -f "$root/$1" ]] && grep -oE "$2" "$root/$1"; }
# inline LINES: one line per value, as one space-separated line for a message.
inline() { tr '\n' ' ' <<<"$1" | sed 's/ *$//'; }

# postgres image
compose_pg=$(grab compose.yaml 'image: postgres:[^[:space:]"]+' | sed 's/^image: //' | sort -u)
pg_major=
if [[ -z "$compose_pg" ]]; then problem "postgres image: found none in compose.yaml"
elif [[ "$(wc -l <<<"$compose_pg")" -ne 1 ]]; then problem "postgres image: compose.yaml names more than one ($(inline "$compose_pg"))"
else pg_major=${compose_pg#postgres:}; pg_major=${pg_major%%[.-]*}; fi

# serverVersion follows the image's major
found=0
for file in compose.yaml api/.env api/.env.*; do
  [[ -f "$root/$file" ]] || continue
  while read -r v; do
    found=$((found + 1))
    [[ -n "$pg_major" && "${v%%.*}" != "$pg_major" ]] && problem "serverVersion=$v in $file, the postgres image is $pg_major"
  done < <(grep -oE 'serverVersion=[0-9.]+' "$root/$file" | sed 's/^serverVersion=//')
done
((found)) || problem "serverVersion: found none in compose.yaml or api/.env*"

# PHP minor
image_php=$(grab infra/api/Dockerfile 'frankenphp:[^[:space:]]*-php[0-9]+\.[0-9]+' | sed 's/.*-php//' | head -1)
composer_php=$([[ -f "$root/api/composer.json" ]] && jq -r '.require.php // empty' "$root/api/composer.json" | grep -oE '[0-9]+\.[0-9]+' | head -1)
if [[ -z "$image_php" ]]; then problem "PHP: found none in infra/api/Dockerfile (dunglas/frankenphp:<v>-php<X.Y>-<os>)"
elif [[ -z "$composer_php" ]]; then problem "PHP: found none in api/composer.json require.php"
elif [[ "$composer_php" != "$image_php" ]]; then problem "PHP: api/composer.json requires >=$composer_php, infra/api/Dockerfile runs $image_php"; fi

# Node major
nvmrc=$([[ -f "$root/web/.nvmrc" ]] && tr -d ' v\n' < "$root/web/.nvmrc"); nvmrc=${nvmrc%%.*}
image_node=$(grab infra/web/Dockerfile 'FROM node:[0-9]+' | grep -oE '[0-9]+$' | head -1)
# One Node image: every stage that runs Node starts from the stage that names it, so this is the only copy to read.
node_images=$(grab infra/web/Dockerfile 'FROM node:[^ ]+' | wc -l)
engines=$([[ -f "$root/web/package.json" ]] && jq -r '.engines.node // empty' "$root/web/package.json" | grep -oE '[0-9]+' | head -1)
if [[ -z "$nvmrc" ]]; then problem "Node: found none in web/.nvmrc"
else
  if [[ -z "$image_node" ]]; then problem "Node: found none in infra/web/Dockerfile (FROM node:<v>)"
  elif [[ "$image_node" != "$nvmrc" ]]; then problem "Node: infra/web/Dockerfile runs $image_node, web/.nvmrc says $nvmrc"; fi
  ((node_images > 1)) && problem "Node: infra/web/Dockerfile names $node_images node images; start every stage from the one that names it"
  if [[ -z "$engines" ]]; then problem "Node: found none in web/package.json engines.node"
  elif [[ "$engines" != "$nvmrc" ]]; then problem "Node: web/package.json engines wants >=$engines, web/.nvmrc says $nvmrc"; fi
fi

# The tools image runs the web image's Node, to the patch: a gate that passes on one and ships on another proves nothing.
image_node_full=$(grab infra/web/Dockerfile 'FROM node:[0-9]+\.[0-9]+\.[0-9]+' | head -1 | sed 's/^FROM node://')
tools_node_full=$(grab infra/web/tools.Dockerfile 'FROM node:[0-9]+\.[0-9]+\.[0-9]+' | head -1 | sed 's/^FROM node://')
if [[ -z "$tools_node_full" ]]; then problem "Node: found none in infra/web/tools.Dockerfile (FROM node:<X.Y.Z>-...)"
elif [[ -n "$image_node_full" && "$tools_node_full" != "$image_node_full" ]]; then
  problem "Node: infra/web/tools.Dockerfile runs $tools_node_full, infra/web/Dockerfile runs $image_node_full"
elif [[ -n "$nvmrc" && "${tools_node_full%%.*}" != "$nvmrc" ]]; then
  problem "Node: infra/web/tools.Dockerfile runs $tools_node_full, web/.nvmrc says $nvmrc"; fi

# Playwright: the browser build the tools image's system libraries are installed for is the one the tests run
pw_lock=$([[ -f "$root/web/package-lock.json" ]] && jq -r '.packages["node_modules/@playwright/test"].version // empty' "$root/web/package-lock.json")
pw_image=$(grab infra/web/tools.Dockerfile 'ARG PLAYWRIGHT_VERSION=[0-9.]+' | head -1 | sed 's/.*=//')
if [[ -z "$pw_lock" ]]; then problem "Playwright: found no @playwright/test in web/package-lock.json"
elif [[ -z "$pw_image" ]]; then problem "Playwright: found no ARG PLAYWRIGHT_VERSION in infra/web/tools.Dockerfile"
elif [[ "$pw_lock" != "$pw_image" ]]; then problem "Playwright: infra/web/tools.Dockerfile installs $pw_image, web/package-lock.json locks $pw_lock"; fi

# Symfony minor
if [[ -f "$root/api/composer.json" ]]; then
  sf=$(jq -r '.extra.symfony.require // empty' "$root/api/composer.json")
  mapfile -t sf_pins < <(jq -r '(.require + (."require-dev" // {})) | to_entries[] | select(.key | startswith("symfony/")) | select(.value | test("^[0-9]+\\.[0-9]+\\.\\*$")) | "\(.key) \(.value)"' "$root/api/composer.json")
  if [[ -z "$sf" ]]; then problem "Symfony: found no extra.symfony.require in api/composer.json"
  elif ((${#sf_pins[@]} == 0)); then problem "Symfony: found no symfony/* package pinned X.Y.* in api/composer.json"
  else for p in "${sf_pins[@]}"; do [[ "${p#* }" == "$sf" ]] || problem "Symfony: ${p%% *} is ${p#* }, extra.symfony.require is $sf"; done; fi
else problem "Symfony: found no api/composer.json"; fi

# Angular major
if [[ -f "$root/web/package.json" ]]; then
  mapfile -t ng < <(jq -r '((.dependencies // {}) + (.devDependencies // {})) | to_entries[] | select(.key | startswith("@angular/")) | "\(.key) \(.value)"' "$root/web/package.json")
  core=$(printf '%s\n' "${ng[@]}" | awk '$1 == "@angular/core" {print $2}' | grep -oE '[0-9]+' | head -1)
  if [[ -z "$core" ]]; then problem "Angular: found no @angular/core in web/package.json"
  else for p in "${ng[@]}"; do m=$(grep -oE '[0-9]+' <<<"${p#* }" | head -1); [[ "$m" == "$core" ]] || problem "Angular: ${p%% *} is ${p#* }, @angular/core is major $core"; done; fi
else problem "Angular: found no web/package.json"; fi

if ((${#problems[@]})); then
  printf 'version-pins: FAIL — %d cop(ies) disagree (a bump moves every copy: docs/UPDATE.md):\n' "${#problems[@]}"
  printf '  %s\n' "${problems[@]}"
  exit 1
fi
printf 'version-pins: OK — 7 pins agree across their copies (postgres %s, PHP %s, Node %s, Playwright %s, Symfony %s, Angular %s)\n' \
  "${compose_pg#postgres:}" "$image_php" "$nvmrc" "$pw_lock" "$sf" "$core"
