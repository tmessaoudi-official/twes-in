#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
GATE=$HERE/e2e-environment.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
tree() { # passed variables, then reads as "file:NAME"
  local d; d=$(mktemp -d); mkdir -p "$d/web/e2e" "$d/web/e2e-gallery"
  printf 'services:\n  api:\n    environment:\n      MAILPIT_URL: x\n  web-tools:\n    image: twes-in-web-tools\n    environment:\n      HOME: /tmp\n' > "$d/compose.yaml"
  local passed=$1; shift
  for name in $passed; do printf '      %s:\n' "$name" >> "$d/compose.yaml"; done
  printf '    volumes:\n      - "x:x"\n  worker:\n    environment:\n      CI: x\n' >> "$d/compose.yaml"
  for read in "$@"; do printf "const v = process.env['%s'];\n" "${read#*:}" >> "$d/web/${read%%:*}"; done
  echo "$d"
}

echo "e2e-environment gate"
d=$(tree "BASE_URL CI" "playwright.config.ts:BASE_URL" "e2e/a.spec.ts:CI" "e2e-gallery/b.spec.ts:BASE_URL")
out=$(bash "$GATE" --root "$d" --floor 2 2>&1); check "every variable read is passed into web-tools" $? 0 "$out" "OK — 2 variables"

d=$(tree "BASE_URL" "playwright.config.ts:BASE_URL" "e2e/mailpit.ts:MAILPIT_URL")
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "a variable read but not passed is named with its file" $? 1 "$out" "MAILPIT_URL, read in web/e2e/mailpit.ts"

d=$(tree "BASE_URL" "playwright.config.ts:BASE_URL" "e2e/a.spec.ts:CI")
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "another service passing it does not count" $? 1 "$out" "CI, read in web/e2e/a.spec.ts"

d=$(tree "BASE_URL E2E_TOTP_SECRET" "e2e/a.ts:E2E_TOTP_SECRET" "e2e/a.ts:BASE_URL")
out=$(bash "$GATE" --root "$d" --floor 2 2>&1); check "a name with digits is read" $? 0 "$out" "OK — 2 variables"

d=$(tree "BASE_URL" "e2e/a.ts:BASE_URL")
out=$(bash "$GATE" --root "$d" --floor 5 2>&1); check "fewer reads than the floor fail rather than pass on nothing" $? 1 "$out" "below the floor of 5"

echo "e2e-environment gate: $pass passed, $fail failed"
((fail == 0))
