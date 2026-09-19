#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/version-pins.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree in which every copy of every pin agrees, shaped like the real files.
tree() {
  local d; d=$(mktemp -d)
  mkdir -p "$d/.github/workflows" "$d/infra/api" "$d/infra/web" "$d/api" "$d/web"
  cat > "$d/compose.yaml" <<'EOF'
services:
  postgres:
    image: postgres:18.6-trixie
  api:
    environment:
      DATABASE_URL: "postgresql://twes:x@postgres:5432/twes?serverVersion=18&charset=utf8"
EOF
  cat > "$d/.github/workflows/ci.yml" <<'EOF'
jobs:
  licences:
    steps:
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
  api:
    services:
      postgres:
        image: postgres:18.6-trixie
    env:
      DATABASE_URL: "postgresql://twes:twes@127.0.0.1:5433/twes?serverVersion=18&charset=utf8"
    steps:
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          extensions: pdo_pgsql, intl, zip, opcache, bcmath
EOF
  cat > "$d/infra/api/Dockerfile" <<'EOF'
FROM dunglas/frankenphp:1.12.7-php8.5-trixie
RUN install-php-extensions pdo_pgsql intl zip opcache bcmath
EOF
  printf 'FROM node:26.8.2-alpine AS build\nFROM nginx:1.31.5-alpine\n' > "$d/infra/web/Dockerfile"
  printf 'DATABASE_URL="postgresql://twes:twes@127.0.0.1:5433/twes?serverVersion=18&charset=utf8"\n' > "$d/api/.env"
  cat > "$d/api/composer.json" <<'EOF'
{
  "require": { "php": ">=8.5", "symfony/console": "8.1.*", "symfony/flex": "^2", "symfony/monolog-bundle": "^4.1" },
  "require-dev": { "symfony/browser-kit": "8.1.*" },
  "extra": { "symfony": { "require": "8.1.*" } }
}
EOF
  printf '26\n' > "$d/web/.nvmrc"
  cat > "$d/web/package.json" <<'EOF'
{
  "engines": { "node": ">=26.0.0" },
  "dependencies": { "@angular/core": "^22.1.0", "@angular/material": "^22.1.6", "rxjs": "~7.8.0" },
  "devDependencies": { "@angular/cli": "^22.1.5" }
}
EOF
  echo "$d"
}
run() { bash "$GATE" --root "$1" 2>&1; }
echo "version-pins gate"

d=$(tree); out=$(run "$d"); check "every copy agreeing passes" $? 0 "$out" "OK — 7 pins agree"

d=$(tree); sed -i 's/postgres:18.6-trixie/postgres:18.7-trixie/' "$d/.github/workflows/ci.yml"
out=$(run "$d"); check "CI's postgres image apart from compose's is refused" $? 1 "$out" "postgres image"

d=$(tree); sed -i 's/serverVersion=18/serverVersion=17/' "$d/api/.env"
out=$(run "$d"); check "a serverVersion that is not the image's major is named with its file" $? 1 "$out" "api/.env"

d=$(tree); sed -i '0,/8.5/s/8.5/8.4/' "$d/.github/workflows/ci.yml"
out=$(run "$d"); check "one CI php-version left behind is refused" $? 1 "$out" "PHP"

d=$(tree); sed -i 's/">=8.5"/">=8.4"/' "$d/api/composer.json"
out=$(run "$d"); check "composer.json's php floor apart from the image is refused" $? 1 "$out" "PHP"

d=$(tree); sed -i 's/, bcmath//' "$d/.github/workflows/ci.yml"
out=$(run "$d"); check "an extension the image has and CI lacks is named" $? 1 "$out" "bcmath"

d=$(tree); sed -i 's/node:26.8.2/node:24.1.0/' "$d/infra/web/Dockerfile"
out=$(run "$d"); check "the web image's Node major apart from .nvmrc is refused" $? 1 "$out" "Node"

d=$(tree); sed -i 's/>=26.0.0/>=24.0.0/' "$d/web/package.json"
out=$(run "$d"); check "package.json engines apart from .nvmrc is refused" $? 1 "$out" "Node"

d=$(tree); sed -i 's|"symfony/browser-kit": "8.1.\*"|"symfony/browser-kit": "8.0.*"|' "$d/api/composer.json"
out=$(run "$d"); check "a symfony/* package left on the old minor is named" $? 1 "$out" "symfony/browser-kit"

d=$(tree); sed -i 's|"@angular/cli": "^22.1.5"|"@angular/cli": "^21.2.0"|' "$d/web/package.json"
out=$(run "$d"); check "an @angular/* package on another major is named" $? 1 "$out" "@angular/cli"

d=$(tree); sed -i '/image: postgres/d' "$d/.github/workflows/ci.yml"
out=$(run "$d"); check "a pin that disappeared from one file is refused, not skipped" $? 1 "$out" "found none"

d=$(tree); sed -i 's/serverVersion=18/serverVersion=17/' "$d/compose.yaml" "$d/api/.env" "$d/.github/workflows/ci.yml"
out=$(run "$d"); check "every serverVersion agreeing with each other but not the image is still refused" $? 1 "$out" "serverVersion"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
