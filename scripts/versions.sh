#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# `make versions`: every version pin, read from the file that holds it, so no document has to repeat a number that
# goes stale. docs/UPDATE.md says what each one is, where its copies are and how to bump it; this says what it is now.
# Locked versions come from composer.lock and package-lock.json, which is what an image or a clean install builds.
set -euo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$root"
row() { printf '  %-40s %-32s %s\n' "$1" "$2" "$3"; }
locked_php() { jq -r --arg n "$1" '[.packages[], ."packages-dev"[]] | map(select(.name == $n)) | .[0].version // "-"' api/composer.lock; }
locked_js() { jq -r --arg n "node_modules/$1" '.packages[$n].version // "-"' web/package-lock.json; }
wanted_php() { jq -r --arg n "$1" '(.require + ."require-dev")[$n] // "-"' api/composer.json; }
wanted_js() { jq -r --arg n "$1" '(.dependencies + .devDependencies)[$n] // "-"' web/package.json; }

echo "Images (docker)"
while read -r file line; do row "${line##* }" "$file" ""; done < <(
  grep -HoE '^[[:space:]]*image: [^[:space:]]+' compose.yaml .github/workflows/ci.yml | sed 's/:[[:space:]]*image:/ image:/' | sort -u
  grep -HoE '^FROM [^[:space:]]+|COPY --from=[^[:space:]]+:[^[:space:]]+' infra/api/Dockerfile infra/web/Dockerfile \
    | sed -E 's/:(FROM |COPY --from=)/ /')

echo
echo "Runtimes"
row "PHP (api image)" "infra/api/Dockerfile" "$(grep -oE 'php[0-9]+\.[0-9]+' infra/api/Dockerfile | head -1)"
row "PHP extensions" "infra/api/Dockerfile" "$(grep -oE 'install-php-extensions .*' infra/api/Dockerfile | cut -d' ' -f2-)"
row "PHP (CI)" ".github/workflows/ci.yml" "$(grep -oE "php-version: '[^']+'" .github/workflows/ci.yml | sort -u | cut -d"'" -f2 | paste -sd' ')"
row "Node (host, CI)" "web/.nvmrc" "$(cat web/.nvmrc)"
row "Node engines" "web/package.json" "$(jq -r '.engines.node' web/package.json)"
row "PostgreSQL serverVersion" "compose.yaml, api/.env, ci.yml" "$(git grep -ohE 'serverVersion=[0-9.]+' -- compose.yaml api/.env .github/workflows/ci.yml | sed 's/^serverVersion=//' | sort -u | paste -sd' ')"

echo
echo "API frameworks (wanted in api/composer.json → locked in api/composer.lock)"
row "Symfony (extra.symfony.require)" "api/composer.json" "$(jq -r '.extra.symfony.require' api/composer.json) → $(locked_php symfony/framework-bundle)"
for p in api-platform/core doctrine/orm doctrine/doctrine-bundle doctrine/doctrine-migrations-bundle symfony/monolog-bundle \
  phpunit/phpunit phpstan/phpstan friendsofphp/php-cs-fixer; do
  row "$p" "api/composer.json" "$(wanted_php "$p") → $(locked_php "$p")"
done

echo
echo "Web frameworks (wanted in web/package.json → locked in web/package-lock.json)"
for p in @angular/core @angular/material @angular/cli typescript rxjs @playwright/test @ngx-translate/core centrifuge; do
  row "$p" "web/package.json" "$(wanted_js "$p") → $(locked_js "$p")"
done

echo
echo "CI actions (.github/workflows/ci.yml)"
grep -oE 'uses: [^[:space:]]+' .github/workflows/ci.yml | sort -u | while read -r _ action; do row "${action%@*}" "" "${action##*@}"; done

echo
echo "What is newer: docs/UPDATE.md § \"Find what is out of date\"."
