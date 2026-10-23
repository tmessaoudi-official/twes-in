#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/signed-out-exits.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
page() { printf '<app-signed-out-layout>\n  <h1>x</h1>\n%b</app-signed-out-layout>\n' "${2:-}" > "$1"; }
repo() {
  local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/x"
  for ((i = 0; i < 10; i++)); do page "$d/web/src/app/x/p$i-page.html" '  <p twesAuthAfter><a routerLink="/login">x</a></p>\n'; done
  git -C "$d" add -A; echo "$d"
}
echo "signed-out-exits gate"
d=$(repo)
out=$(bash "$GATE" --root "$d" 2>&1); check "pages offering their way out under the card pass" $? 0 "$out" "OK — 10 signed-out pages"
page "$d/web/src/app/x/p3-page.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a page with no way out is named" $? 1 "$out" "x/p3-page.html draws a signed-out page with no way out"
page "$d/web/src/app/x/p3-page.html" '  <!-- way-out: signing out -->\n  <button>x</button>\n'; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a page naming the way out in its card passes" $? 0 "$out" "1 named in it"
page "$d/web/src/app/x/p3-page.html" '  <!-- way-out: -->\n'; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a way-out marker naming nothing is no way out" $? 1 "$out" "x/p3-page.html"
d=$(repo); printf "template: \`<app-signed-out-layout></app-signed-out-layout>\`\n" > "$d/web/src/app/x/inline-page.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "an inline template is read too" $? 1 "$out" "x/inline-page.ts"
d=$(repo); printf "template: \`<app-signed-out-layout></app-signed-out-layout>\`\n" > "$d/web/src/app/x/a.spec.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a spec is not a page" $? 0 "$out" "OK"
d=$(repo); git -C "$d" rm -qf "$d"/web/src/app/x/p[0-4]-page.html
out=$(bash "$GATE" --root "$d" 2>&1); check "far fewer pages than written reds rather than passing over nothing" $? 1 "$out" "no longer sees"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
