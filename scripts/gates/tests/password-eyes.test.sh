#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/password-eyes.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
repo() { local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/x"; echo "$d"; }
field() { printf '<mat-form-field>\n  <input\n    matInput\n    %s\n    type="password"\n  />\n  %s\n</mat-form-field>\n' "$1" "$2"; }
echo "password-eyes gate"
d=$(repo); field '#pw' '<app-password-toggle matSuffix [field]="pw" />' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a password field with its eye passes" $? 0 "$out" "OK — 1 password field"
field '#pw' '' > "$d/web/src/app/x/b.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a password field without an eye is caught, by file and line" $? 1 "$out" "web/src/app/x/b.html:2"
d=$(repo); field '#pw' '<app-password-toggle matSuffix [field]="other" />' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "an eye acting on another input does not count" $? 1 "$out" "web/src/app/x/a.html:2"
d=$(repo); field '' '<app-password-toggle matSuffix [field]="pw" />' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "an input with no reference has no eye to name it" $? 1 "$out" "web/src/app/x/a.html:2"
d=$(repo); field '#pw' '<app-password-toggle [field]="pw" />' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "an eye that is not the field's suffix does not count" $? 1 "$out" "web/src/app/x/a.html:2"
d=$(repo); { field '#a' ''; field '#b' '<app-password-toggle matSuffix [field]="a" />'; } > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a later field's eye never covers an earlier field" $? 1 "$out" "web/src/app/x/a.html:2"
d=$(repo); printf "@Component({ template: \`<mat-form-field><input matInput type=\"password\" /></mat-form-field>\` })\nexport class A {}\n" > "$d/web/src/app/x/a.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "an inline template in a component is read too" $? 1 "$out" "web/src/app/x/a.ts:1"
d=$(repo); field '' '' > "$d/web/src/app/x/a.spec.ts"; field '#pw' '<app-password-toggle matSuffix [field]="pw" />' > "$d/web/src/app/x/b.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "specs are not read" $? 0 "$out" "OK — 1 password field"
out=$(bash "$GATE" --root "$d" --min 2 2>&1); check "fewer fields than the floor is a failure, not a pass" $? 1 "$out" "fewer than the 2 expected"
d=$(repo); out=$(bash "$GATE" --root "$d" 2>&1); check "an empty tree is below the default floor" $? 1 "$out" "0 password field(s) found"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
