#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/outcomes-as-toasts.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
repo() { local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/x"; echo "$d"; }
echo "outcomes-as-toasts gate"
d=$(repo); printf '@if (saved()) {\n  <p\n    role="status"\n    data-testid="x-saved"\n  >{{ %s }}</p>\n}\n' "'x.saved' | translate" > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "an inline outcome line is caught, by file, line and test id" $? 1 "$out" "web/src/app/x/a.html:2 x-saved"
d=$(repo); printf "@Component({ template: \`<div role=\"status\" data-testid=\"x-recorded\">ok</div>\` })\nexport class A {}\n" > "$d/web/src/app/x/a.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "an inline component template is read too" $? 1 "$out" "web/src/app/x/a.ts:1 x-recorded"
d=$(repo); printf '<p role="status" data-testid="login-expired">x</p>\n<p role="status" data-testid="signup-sent">x</p>\n' > "$d/web/src/app/x/a.html"; printf '<p role="status">x</p>\n' > "$d/web/src/app/x/a.spec.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a status naming the page's own state is allowed, and specs are not read" $? 0 "$out" "OK — 2 status lines, all page states"
d=$(repo); printf '<p role="status">x</p>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a status without a test id cannot claim to be a page state" $? 1 "$out" "web/src/app/x/a.html:1 (no test id)"
d=$(repo); out=$(bash "$GATE" --root "$d" 2>&1); check "an empty tree is reported, not read as a pass over nothing" $? 0 "$out" "OK — 0 status lines"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
