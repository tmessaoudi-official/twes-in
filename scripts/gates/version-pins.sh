#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Several versions are written in more than one file, and a bump that moves only one copy builds one thing locally and
# tests another in CI (docs/UPDATE.md lists every pin and its copies; docs/SPEC.md § 7, 2026-09-19). This gate
# refuses any copy that disagrees, and any copy that disappeared: a pin found nowhere is a failure, never a skip.
#   postgres image         compose.yaml = .github/workflows/ci.yml
#   serverVersion          every DATABASE_URL (compose.yaml, ci.yml, api/.env*) = the postgres image's major
#   PHP                    infra/api/Dockerfile's frankenphp tag = every php-version in ci.yml = composer.json's floor
#   PHP extensions         infra/api/Dockerfile install-php-extensions = ci.yml extensions
#   Node major             web/.nvmrc = infra/web/Dockerfile's node image = web/package.json engines
#   Symfony minor          every symfony/* pinned "X.Y.*" = composer.json extra.symfony.require
#   Angular major          every @angular/* in web/package.json = @angular/core's
# Usage: version-pins.sh [--root DIR]
set -uo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
ci=.github/workflows/ci.yml
problems=()
problem() { problems+=("$1"); }
# grab FILE ERE: every match of ERE in FILE, one per line (nothing when the file is absent).
grab() { [[ -f "$root/$1" ]] && grep -oE "$2" "$root/$1"; }
# inline LINES: one line per value, as one space-separated line for a message.
inline() { tr '\n' ' ' <<<"$1" | sed 's/ *$//'; }

# postgres image
compose_pg=$(grab compose.yaml 'image: postgres:[^[:space:]"]+' | sed 's/^image: //' | sort -u)
ci_pg=$(grab "$ci" 'image: postgres:[^[:space:]"]+' | sed 's/^image: //' | sort -u)
pg_major=
if [[ -z "$compose_pg" ]]; then problem "postgres image: found none in compose.yaml"
elif [[ -z "$ci_pg" ]]; then problem "postgres image: found none in $ci"
elif [[ "$(printf '%s\n%s\n' "$compose_pg" "$ci_pg" | sort -u | wc -l)" -ne 1 ]]; then
  problem "postgres image: compose.yaml has $(inline "$compose_pg"), $ci has $(inline "$ci_pg")"
else pg_major=${compose_pg#postgres:}; pg_major=${pg_major%%[.-]*}; fi

# serverVersion follows the image's major
found=0
for file in compose.yaml "$ci" api/.env api/.env.*; do
  [[ -f "$root/$file" ]] || continue
  while read -r v; do
    found=$((found + 1))
    [[ -n "$pg_major" && "${v%%.*}" != "$pg_major" ]] && problem "serverVersion=$v in $file, the postgres image is $pg_major"
  done < <(grep -oE 'serverVersion=[0-9.]+' "$root/$file" | sed 's/^serverVersion=//')
done
((found)) || problem "serverVersion: found none in compose.yaml, $ci or api/.env*"

# PHP minor
image_php=$(grab infra/api/Dockerfile 'frankenphp:[^[:space:]]*-php[0-9]+\.[0-9]+' | sed 's/.*-php//' | head -1)
mapfile -t ci_php < <(grab "$ci" "php-version: *['\"]?[0-9]+\.[0-9]+" | grep -oE '[0-9]+\.[0-9]+$')
composer_php=$([[ -f "$root/api/composer.json" ]] && jq -r '.require.php // empty' "$root/api/composer.json" | grep -oE '[0-9]+\.[0-9]+' | head -1)
if [[ -z "$image_php" ]]; then problem "PHP: found none in infra/api/Dockerfile (dunglas/frankenphp:<v>-php<X.Y>-<os>)"
else
  ((${#ci_php[@]})) || problem "PHP: found no php-version in $ci"
  for v in "${ci_php[@]}"; do [[ "$v" == "$image_php" ]] || problem "PHP: $ci says $v, infra/api/Dockerfile says $image_php"; done
  if [[ -z "$composer_php" ]]; then problem "PHP: found none in api/composer.json require.php"
  elif [[ "$composer_php" != "$image_php" ]]; then problem "PHP: api/composer.json requires >=$composer_php, infra/api/Dockerfile runs $image_php"; fi
fi

# PHP extensions
image_ext=$(grab infra/api/Dockerfile 'install-php-extensions[^\\&]*' | sed 's/^install-php-extensions//' | tr -s ' ' '\n' | sed '/^$/d' | sort -u)
ci_ext=$(grab "$ci" 'extensions: .*' | sed 's/^extensions: //' | sed 's/[, ][, ]*/\n/g' | sed '/^$/d' | sort -u)
if [[ -z "$image_ext" ]]; then problem "PHP extensions: found none in infra/api/Dockerfile"
elif [[ -z "$ci_ext" ]]; then problem "PHP extensions: found none in $ci"
else
  only_image=$(comm -23 <(echo "$image_ext") <(echo "$ci_ext")); only_ci=$(comm -13 <(echo "$image_ext") <(echo "$ci_ext"))
  [[ -n "$only_image" ]] && problem "PHP extensions: in infra/api/Dockerfile only: $(inline "$only_image")"
  [[ -n "$only_ci" ]] && problem "PHP extensions: in $ci only: $(inline "$only_ci")"
fi

# Node major
nvmrc=$([[ -f "$root/web/.nvmrc" ]] && tr -d ' v\n' < "$root/web/.nvmrc"); nvmrc=${nvmrc%%.*}
image_node=$(grab infra/web/Dockerfile 'FROM node:[0-9]+' | grep -oE '[0-9]+$' | head -1)
engines=$([[ -f "$root/web/package.json" ]] && jq -r '.engines.node // empty' "$root/web/package.json" | grep -oE '[0-9]+' | head -1)
if [[ -z "$nvmrc" ]]; then problem "Node: found none in web/.nvmrc"
else
  if [[ -z "$image_node" ]]; then problem "Node: found none in infra/web/Dockerfile (FROM node:<v>)"
  elif [[ "$image_node" != "$nvmrc" ]]; then problem "Node: infra/web/Dockerfile runs $image_node, web/.nvmrc says $nvmrc"; fi
  if [[ -z "$engines" ]]; then problem "Node: found none in web/package.json engines.node"
  elif [[ "$engines" != "$nvmrc" ]]; then problem "Node: web/package.json engines wants >=$engines, web/.nvmrc says $nvmrc"; fi
fi

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
printf 'version-pins: OK — 7 pins agree across their copies (postgres %s, PHP %s, Node %s, Symfony %s, Angular %s)\n' \
  "${compose_pg#postgres:}" "$image_php" "$nvmrc" "$sf" "$core"
