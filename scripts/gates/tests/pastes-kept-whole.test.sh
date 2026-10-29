#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/pastes-kept-whole.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
repo() { local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/x"; echo "$d"; }
field() { printf '<mat-form-field>\n  <input\n    matInput\n    %s\n  />\n</mat-form-field>\n' "$1"; }
echo "pastes-kept-whole gate"
d=$(repo); field 'formControlName="name"' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a field with no limit of its own passes" $? 0 "$out" "OK — 1 inputs read"
field 'maxlength="80"' > "$d/web/src/app/x/b.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a static maxlength is caught, by file and line" $? 1 "$out" "web/src/app/x/b.html:4"
d=$(repo); field '[maxlength]="max"' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a bound maxlength is caught" $? 1 "$out" "web/src/app/x/a.html:4"
d=$(repo); field '[attr.maxlength]="field.maxLength ?? null"' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "an attribute binding is caught" $? 1 "$out" "web/src/app/x/a.html:4"
d=$(repo); field 'maxlength="7" <!-- paste-cut: a colour is #rrggbb -->' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a fixed format saying why on its line passes" $? 0 "$out" "OK"
d=$(repo); printf '<mat-form-field>\n  <!-- paste-cut: a time is HH:MM -->\n  <input matInput maxlength="5" />\n</mat-form-field>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a reason on the line above passes" $? 0 "$out" "OK"
d=$(repo); printf '<!-- paste-cut: a time is HH:MM -->\n<input\n  matInput\n  autocomplete="off"\n  maxlength="5"\n/>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a reason just before an element covers its attributes" $? 0 "$out" "OK"
d=$(repo); printf '<!-- paste-cut: a time is HH:MM -->\n<input matInput maxlength="5" />\n<input\n  matInput\n  maxlength="9"\n/>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a reason ends with its element and never reaches the next" $? 1 "$out" "web/src/app/x/a.html:5"
d=$(repo); printf '<mat-form-field>\n  <!-- paste-cut: -->\n  <input matInput maxlength="5" />\n</mat-form-field>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a reason left empty is no reason" $? 1 "$out" "web/src/app/x/a.html:3"
d=$(repo); printf '<!-- paste-cut: one file -->\n' > "$d/web/src/app/x/a.html"; printf '<input matInput maxlength="5" />\n' > "$d/web/src/app/x/b.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "one file's last line never excuses the next file's first" $? 1 "$out" "web/src/app/x/b.html:1"
d=$(repo); printf "@Component({ template: \`<input matInput maxlength=\"9\" />\` })\nexport class A { field = { maxLength: 9 }; }\n" > "$d/web/src/app/x/a.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "an inline template is read, a property named maxLength is not an attribute" $? 1 "$out" "web/src/app/x/a.ts:1"
d=$(repo); field 'maxlength="3"' > "$d/web/src/app/x/a.spec.ts"; field '' > "$d/web/src/app/x/b.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "specs are not read" $? 0 "$out" "OK — 1 inputs read"
out=$(bash "$GATE" --root "$d" --min 2 2>&1); check "fewer inputs than the floor is a failure, not a pass" $? 1 "$out" "fewer than the 2 expected"
d=$(repo); out=$(bash "$GATE" --root "$d" 2>&1); check "an empty tree is below the default floor" $? 1 "$out" "0 inputs read"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
