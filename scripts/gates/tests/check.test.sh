#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
SCRIPT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../check" && pwd)/check.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
absent() { if [[ "$2" != *"$3"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (found '$3')"; echo "$2" | sed 's/^/       /'; fi; }
# A tree shaped like the real one, the script copied in: it works from the tree it sits in.
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/scripts/check" "$d/api/src/Money" "$d/api/tests/Unit" "$d/api/config" "$d/web/src/app/x" \
    "$d/web/src/app/api" "$d/web/e2e" "$d/web/public/i18n" "$d/docs"
  cp "$SCRIPT" "$d/scripts/check/check.sh"
  for f in api/src/Money/Sum.php api/tests/Unit/SumTest.php api/config/services.yaml web/src/app/x/x-page.ts web/src/app/x/x-page.html \
    web/src/app/x/x-page.spec.ts web/src/app/x/lonely.ts web/src/app/api/types.ts web/e2e/x.spec.ts web/public/i18n/fr.json docs/START.md Makefile; do
    echo x > "$d/$f"
  done
  echo "$d"
}
plan() { (cd "$1" && shift && bash scripts/check/check.sh plan "$@" 2>&1); }
echo "check: what make check gives each tool"

d=$(tree); out=$(plan "$d" api/src/Money/Sum.php)
check "a PHP source is formatted and analysed" $? 0 "$out" $'api-format src/Money/Sum.php\napi-stan'
check "a PHP source with no test given says PHPUnit is not run" 0 0 "$out" "api-untested src/Money/Sum.php"
absent "a PHP source alone runs no test" "$out" "api-test"

out=$(plan "$d" api/src/Money/Sum.php api/tests/Unit/SumTest.php)
check "a PHP test is run" $? 0 "$out" "api-test tests/Unit/SumTest.php"
absent "a test given silences the untested line" "$out" "api-untested"

out=$(plan "$d" web/src/app/x/x-page.ts)
check "a web source is formatted and linted" $? 0 "$out" $'web-format src/app/x/x-page.ts\nweb-lint src/app/x/x-page.ts'
check "a web source runs its sibling spec" 0 0 "$out" "web-test src/app/x/x-page.spec.ts"

out=$(plan "$d" web/src/app/x/x-page.html web/src/app/x/x-page.spec.ts web/src/app/x/x-page.ts)
check "a spec reached three ways is run" $? 0 "$out" $'\nweb-test src/app/x/x-page.spec.ts'
absent "…once" "$out" "x-page.spec.ts src/app/x/x-page.spec.ts"

out=$(plan "$d" web/src/app/x/lonely.ts)
absent "a web source with no spec runs no test" "$out" "web-test"

out=$(plan "$d" web/e2e/x.spec.ts web/public/i18n/fr.json)
check "an e2e spec and a translation are formatted" $? 0 "$out" "web-format e2e/x.spec.ts public/i18n/fr.json"
check "an e2e spec is named as not run" 0 0 "$out" "web-e2e e2e/x.spec.ts"
absent "nothing outside src/ is linted" "$out" "web-lint"
absent "an e2e spec is not given to ng test" "$out" "web-test"

out=$(plan "$d" Makefile docs/START.md api/config/services.yaml web/src/app/api/types.ts)
check "what no tool here checks is named, never dropped" $? 0 "$out" "unchecked Makefile api/config/services.yaml docs/START.md web/src/app/api/types.ts"
absent "the generated API types are not formatted" "$out" "web-format"

out=$(plan "$d" api/src/Money/Gone.php web/src/app/x/gone.spec.ts)
check "a deleted file gives nothing to check" $? 0 "x$out" "x"
[[ -z "$out" ]] && { pass=$((pass+1)); echo "  ok   …and the plan is empty"; } || { fail=$((fail+1)); echo "  FAIL deleted files left: $out"; }

out=$(plan "$d" "$d/api/src/Money/Sum.php" ./web/src/app/x/lonely.ts)
check "an absolute or ./ path is the same file" $? 0 "$out" "api-format src/Money/Sum.php"
check "…for the web too" 0 0 "$out" "web-lint src/app/x/lonely.ts"

out=$(plan "$d" "api/src/Money/A b.php")
check "a path with a space is refused" $? 2 "$out" "refused"

# host: which containers start. The Makefile hands the commands in; here each one only says it ran.
host() { (cd "$1" && shift && CHECK_TOOLS='echo TOOLS' CHECK_WEB_TOOLS='echo WEB' CHECK_NPM_INSTALL=true CHECK_POSTGRES='echo POSTGRES' \
  bash scripts/check/check.sh host "$@" 2>&1); }
out=$(host "$d" web/src/app/x/x-page.ts)
check "web files start the web toolchain alone" $? 0 "$out" "WEB sh -c true && bash ../scripts/check/check.sh web web/src/app/x/x-page.ts"
absent "…not the PHP one" "$out" "TOOLS"
absent "…nor the database" "$out" "POSTGRES"

out=$(host "$d" api/src/Money/Sum.php)
check "a PHP source starts the PHP toolchain" $? 0 "$out" "TOOLS bash scripts/check/check.sh api api/src/Money/Sum.php"
absent "…without the database when no test is run" "$out" "POSTGRES"
absent "…and not the web toolchain" "$out" "WEB"

out=$(host "$d" --fix api/tests/Unit/SumTest.php)
check "a PHP test starts the database first" $? 0 "$out" $'POSTGRES\n'
check "--fix reaches the toolchain" 0 0 "$out" "check.sh api --fix api/tests/Unit/SumTest.php"

out=$(host "$d" docs/START.md)
check "only unchecked files start nothing" $? 0 "$out" "unchecked docs/START.md"
absent "…no toolchain" "$out" "TOOLS"

g=$(tree); (cd "$g" && git init -q && git add -A && git -c user.name=t -c user.email=t@t commit -qm init && echo y > web/src/app/x/lonely.ts \
  && rm api/src/Money/Sum.php && echo y > api/tests/Unit/NewTest.php)
out=$(host "$g")
check "with no files named, it takes what changed against HEAD and the untracked" $? 0 "$out" "api-test tests/Unit/NewTest.php"
check "…the changed web file too" 0 0 "$out" "web-lint src/app/x/lonely.ts"
absent "…not the deleted one" "$out" "Sum.php"
(cd "$g" && git checkout -q -- . && rm api/tests/Unit/NewTest.php)
out=$(host "$g")
check "nothing changed checks nothing" $? 0 "$out" "nothing changed"
absent "…and starts nothing" "$out" "TOOLS"

# api and web: what each tool is given (CHECK_DRY prints the command instead of running it).
dry() { (cd "$1" && shift && CHECK_DRY=1 bash scripts/check/check.sh "$@" 2>&1); }
out=$(dry "$d" api api/src/Money/Sum.php web/src/app/x/lonely.ts)
check "php-cs-fixer is given the config and the files under api/" $? 0 "$out" \
  "RUN (api) vendor/bin/php-cs-fixer check --diff --config=.php-cs-fixer.dist.php --path-mode=intersection src/Money/Sum.php"
check "PHPStan runs as the gate runs it" 0 0 "$out" "RUN (api) composer stan"
check "the untested source is said aloud" 0 0 "$out" "PHPUnit not run"
absent "no PHPUnit with no test" "$out" "phpunit"
absent "the web file is not the PHP toolchain's" "$out" "lonely"

out=$(dry "$d" api --fix api/src/Money/Sum.php)
check "--fix rewrites" $? 0 "$out" "RUN (api) vendor/bin/php-cs-fixer fix --config="

out=$(dry "$d" api api/tests/Unit/SumTest.php)
check "the test database is migrated" $? 0 "$out" "RUN (api) bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test"
check "…and granted to the application role, as composer test does" 0 0 "$out" "RUN (api) bin/console app:database:app-role --no-interaction --env=test"
check "PHPUnit is given the test" 0 0 "$out" "RUN (api) vendor/bin/phpunit tests/Unit/SumTest.php"

out=$(dry "$d" api docs/START.md)
check "no PHP file runs no PHP tool" $? 0 "$out" "api: nothing to check"
absent "…not even php-cs-fixer, which would check the whole tree" "$out" "RUN"

out=$(dry "$d" web web/public/i18n/fr.json)
check "a translation is given to prettier alone" $? 0 "$out" "RUN (web) npx prettier --check --ignore-unknown public/i18n/fr.json"
absent "…not to eslint, which would lint the whole tree" "$out" "eslint"
absent "…nor to ng test, which would run every spec" "$out" "ng test"

out=$(dry "$d" web web/src/app/x/x-page.ts web/src/app/x/x-page.spec.ts web/e2e/x.spec.ts)
check "eslint is given the sources" $? 0 "$out" "RUN (web) npx eslint src/app/x/x-page.spec.ts src/app/x/x-page.ts"
check "ng test is given each spec" 0 0 "$out" "RUN (web) npx ng test --watch=false --include=src/app/x/x-page.spec.ts"
check "an e2e spec is said not run" 0 0 "$out" "make e2e"

out=$(dry "$d" web --fix web/src/app/x/lonely.ts)
check "--fix rewrites the format and the lint" $? 0 "$out" $'RUN (web) npx prettier --write --ignore-unknown src/app/x/lonely.ts\nRUN (web) npx eslint --fix src/app/x/lonely.ts'

# A step's own exit and tally decide, not a pipe's: a fixer that finds something, a PHPUnit that ran nothing.
f=$(tree); mkdir -p "$f/api/vendor/bin" "$f/bin"
printf '#!/bin/sh\necho "Found 1 of 1 files that can be fixed"\nexit 8\n' > "$f/api/vendor/bin/php-cs-fixer"
printf '#!/bin/sh\necho "No tests executed!"\nexit 0\n' > "$f/api/vendor/bin/phpunit"
printf '#!/bin/sh\nexit 0\n' > "$f/bin/composer"; mkdir -p "$f/api/bin"; printf '#!/bin/sh\nexit 0\n' > "$f/api/bin/console"
chmod +x "$f/api/vendor/bin/"* "$f/bin/composer" "$f/api/bin/console"
out=$(cd "$f" && PATH="$f/bin:$PATH" bash scripts/check/check.sh api api/tests/Unit/SumTest.php 2>&1)
check "a fixer's finding fails the check" $? 1 "$out" "FAIL php-cs-fixer (exit 8)"
check "a PHPUnit that ran no test fails though it exits 0" 0 0 "$out" "FAIL phpunit"
check "PHPStan's pass is still reported" 0 0 "$out" "ok   phpstan"

l="$TMPDIR/l"
printf 'OK (9 tests, 175 assertions)\n' > "$l"; out=$(bash "$SCRIPT" tally-phpunit "$l" 2>&1); check "PHPUnit's OK line passes" $? 0 "$out" ""
printf 'OK, but there were issues!\nTests: 3, Assertions: 4, Deprecations: 1.\n' > "$l"; out=$(bash "$SCRIPT" tally-phpunit "$l" 2>&1)
check "PHPUnit's OK with issues passes" $? 0 "$out" ""
printf 'No tests executed!\n' > "$l"; out=$(bash "$SCRIPT" tally-phpunit "$l" 2>&1); check "no OK line fails" $? 1 "$out" "no OK line"
printf '\033[2m Test Files \033[22m \033[1m\033[32m2 passed\033[39m\033[22m\033[90m (2)\033[39m\n' > "$l"
out=$(bash "$SCRIPT" tally-vitest 2 "$l" 2>&1); check "Vitest's coloured tally is read" $? 0 "$out" ""
out=$(bash "$SCRIPT" tally-vitest 3 "$l" 2>&1); check "a spec Vitest dropped fails" $? 1 "$out" "2 of 3"

echo "check: $pass passed, $fail failed"
((fail == 0))
