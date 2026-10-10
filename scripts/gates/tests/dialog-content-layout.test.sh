#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/dialog-content-layout.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
repo() { local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/x"; echo "$d"; }
echo "dialog-content-layout gate"
d=$(repo); printf '<h2 mat-dialog-title>T</h2>\n<mat-dialog-content>\n  <div class="flex flex-col gap-4"><p>a</p></div>\n</mat-dialog-content>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a layout inside the content passes" $? 0 "$out" "OK — 1 mat-dialog-content"
printf '<mat-dialog-content class="flex flex-col gap-4">\n</mat-dialog-content>\n' > "$d/web/src/app/x/b.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a flex column on the content itself is caught, by file and line" $? 1 "$out" "web/src/app/x/b.html:1 flex flex-col gap-4"
d=$(repo); printf '\n<mat-dialog-content\n  class="grid"\n>\n</mat-dialog-content>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a grid on a tag spread over lines is caught at its line" $? 1 "$out" "web/src/app/x/a.html:2 grid"
d=$(repo); printf '<mat-dialog-content class="md:gap-6 text-sm">\n</mat-dialog-content>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a gap behind a breakpoint is caught, the other class left out" $? 1 "$out" "a.html:1 md:gap-6"
d=$(repo); printf '<div mat-dialog-content class="flex">\n</div>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "the attribute form is read too" $? 1 "$out" "a.html:1 flex"
d=$(repo); printf '<mat-dialog-content class="text-sm max-h-96">\n</mat-dialog-content>\n<div class="mat-dialog-content-ish flex"></div>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "classes that lay nothing out, and a class merely named like it, pass" $? 0 "$out" "OK — 1 mat-dialog-content"
d=$(repo); printf "@Component({ template: \`<mat-dialog-content class=\"items-center\"></mat-dialog-content>\` })\nexport class A {}\n" > "$d/web/src/app/x/a.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "an inline template in a component is read too" $? 1 "$out" "web/src/app/x/a.ts:1 items-center"
d=$(repo); printf '<mat-dialog-content class="flex"></mat-dialog-content>\n' > "$d/web/src/app/x/a.spec.ts"; printf '<mat-dialog-content></mat-dialog-content>\n' > "$d/web/src/app/x/b.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "specs are not read" $? 0 "$out" "OK — 1 mat-dialog-content"
out=$(bash "$GATE" --root "$d" --min 2 2>&1); check "fewer than the floor is a failure, not a pass" $? 1 "$out" "fewer than the 2 expected"
d=$(repo); out=$(bash "$GATE" --root "$d" 2>&1); check "an empty tree is below the default floor" $? 1 "$out" "0 mat-dialog-content found"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
