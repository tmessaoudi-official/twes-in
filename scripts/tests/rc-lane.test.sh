#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
SCRIPT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/rc-lane/rc-lane.sh
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
refuses() { if [[ "$4" != *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (found '$5')"; echo "$4" | sed 's/^/       /'; fi; }
composer_json() {
  local f; f=$(mktemp)
  cat > "$f" <<'EOF'
{
  "require": { "php": ">=8.5", "symfony/console": "8.1.*", "symfony/flex": "^2", "symfony/monolog-bundle": "^4.1", "doctrine/orm": "^3.6" },
  "require-dev": { "symfony/browser-kit": "8.1.*", "phpstan/phpstan": ">=2.3.5" },
  "minimum-stability": "stable",
  "extra": { "symfony": { "allow-contrib": "true", "require": "8.1.*" } }
}
EOF
  echo "$f"
}
field() { python3 -I -c 'import json,sys; d=json.load(open(sys.argv[1])); print(eval(sys.argv[2], {}, {"d": d}))' "$1" "$2"; }
echo "rc-lane"

f=$(composer_json); out=$(bash "$SCRIPT" rewrite "$f" 8.2 2>&1); code=$?
check "the rewrite moves every symfony/* constraint on the current minor" $code 0 "$(field "$f" 'd["require"]["symfony/console"], d["require-dev"]["symfony/browser-kit"]')" "('8.2.*', '8.2.*')"
check "the rewrite moves Flex's Symfony line" $code 0 "$(field "$f" 'd["extra"]["symfony"]["require"]')" "8.2.*"
check "the rewrite allows betas and prefers stable releases" $code 0 "$(field "$f" '(d["minimum-stability"], d["prefer-stable"])')" "('beta', True)"
check "the rewrite leaves a symfony/* package on its own line alone" $code 0 "$(field "$f" '(d["require"]["symfony/flex"], d["require"]["symfony/monolog-bundle"])')" "('^2', '^4.1')"
check "the rewrite leaves other vendors and PHP alone" $code 0 "$(field "$f" '(d["require"]["php"], d["require"]["doctrine/orm"], d["require-dev"]["phpstan/phpstan"])')" "('>=8.5', '^3.6', '>=2.3.5')"
check "the rewrite says what it moved" $code 0 "$out" "2 constraints moved to 8.2.*"

f=$(composer_json); out=$(bash "$SCRIPT" rewrite "$f" 8.1 2>&1)
check "a rewrite to the minor already there is refused, never a lane that tests nothing new" $? 2 "$out" "already on 8.1"

f=$(composer_json); python3 -I -c 'import json,sys; d=json.load(open(sys.argv[1])); del d["extra"]; json.dump(d, open(sys.argv[1], "w"))' "$f"
out=$(bash "$SCRIPT" rewrite "$f" 8.2 2>&1)
check "a composer.json with no Flex line is refused, not guessed" $? 2 "$out" "extra.symfony.require"

out=$(bash "$SCRIPT" rewrite "$f" 8 2>&1)
check "a minor that is not X.Y is refused" $? 2 "$out" "X.Y"

d=$(mktemp -d)
printf 'resolve\t0\nstatic\t1\nsuite\t2\n' > "$d/steps.tsv"
printf 'PHP 8.6.0RC5\nSymfony 8.2.0-RC1\n' > "$d/versions.txt"
printf 'Remaining deprecations (2)\n\n * 1x: Since symfony/a 8.2: one\n      in /x.php:1\n * 1x: Since symfony/b 8.2: two\n' > "$d/deprecations.log"
printf 'line\nERROR: the last words of static\n' > "$d/static.log"
out=$(bash "$SCRIPT" summary "$d" 2>&1); code=$?; md=$(cat "$d/summary.md" 2>/dev/null)
check "the summary is written" $code 0 "$md" "PHP 8.6.0RC5"
check "a green step reads as passed" $code 0 "$md" "| resolve | passed |"
check "a red step reads as failed with its exit" $code 0 "$md" "| static | failed (exit 1) |"
check "a red step shows its log's last lines" $code 0 "$md" "ERROR: the last words of static"
check "each deprecation is listed" $code 0 "$md" "Since symfony/b 8.2: two"
check "the deprecations are counted" $code 0 "$md" "2 deprecations"
check "php-cs-fixer's absence is said, never silent" $code 0 "$md" "php-cs-fixer"
out=$(GITHUB_ACTIONS=true bash "$SCRIPT" summary "$d" 2>&1)
check "on GitHub a red step is a warning on the run's page" $? 0 "$out" "::warning title=RC lane::static failed (exit 1)"
refuses "a green step is no warning" $? 0 "$out" "resolve failed"

d=$(mktemp -d); printf 'resolve\t1\n' > "$d/steps.tsv"; : > "$d/versions.txt"
out=$(bash "$SCRIPT" summary "$d" 2>&1); md=$(cat "$d/summary.md")
check "a lane that could not resolve says the later steps were not run" $? 0 "$md" "not run"
check "no deprecation log reads as none read, not as zero" $? 0 "$md" "deprecations: not read"

# A stand-in Composer first on PATH, so a guard that lets the run through can never install anything from here.
mkdir -p "$TMPDIR/bin"; printf '#!/bin/sh\necho called >> "%s/composer-called"\nexit 1\n' "$TMPDIR" > "$TMPDIR/bin/composer"; chmod +x "$TMPDIR/bin/composer"
out=$(PATH="$TMPDIR/bin:$PATH" TMPDIR="$ROOT/var/rc-lane-test-$$" bash "$SCRIPT" run "$TMPDIR/out" 2>&1); code=$?
rm -rf "$ROOT/var/rc-lane-test-$$"
check "a run whose copy of api/ would sit inside the working tree is refused" $code 2 "$out" "outside the working tree"
check "a refused run installs nothing" 0 0 "$( [[ -e "$TMPDIR/composer-called" ]] && echo called || echo untouched)" "untouched"

out=$(bash "$SCRIPT" nonsense 2>&1)
check "an unknown command shows the usage" $? 2 "$out" "usage"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
