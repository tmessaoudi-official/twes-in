#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/numbers-as-typed.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
repo() { local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/x"; echo "$d"; }
field() { printf '<mat-form-field>\n  <input\n    matInput\n    %s\n  />\n</mat-form-field>\n' "$1"; }
echo "numbers-as-typed gate"
d=$(repo); field 'appCount type="text"' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a count typed as text passes" $? 0 "$out" "OK — 1 inputs read"
field 'type="number"' > "$d/web/src/app/x/b.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a browser number is caught, by file and line" $? 1 "$out" "web/src/app/x/b.html:4"
d=$(repo); field 'type = "number"' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "spaces around the sign change nothing" $? 1 "$out" "web/src/app/x/a.html:4"
d=$(repo); printf '<!-- number-input: a range slider reads the browser number -->\n<input\n  matInput\n  type="number"\n/>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a reason just before an element covers it" $? 0 "$out" "OK"
d=$(repo); printf '<!-- number-input: -->\n<input matInput type="number" />\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a reason left empty is no reason" $? 1 "$out" "web/src/app/x/a.html:2"
d=$(repo); printf "@Component({ template: \`<input matInput type=\"number\" />\` })\nexport class A { kind = 'number'; }\n" > "$d/web/src/app/x/a.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "an inline template is read" $? 1 "$out" "web/src/app/x/a.ts:1"
d=$(repo); field 'type="number"' > "$d/web/src/app/x/a.spec.ts"; field '' > "$d/web/src/app/x/b.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "specs are not read" $? 0 "$out" "OK — 1 inputs read"
out=$(bash "$GATE" --root "$d" --min 2 2>&1); check "fewer inputs than the floor is a failure, not a pass" $? 1 "$out" "fewer than the 2 expected"
d=$(repo); out=$(bash "$GATE" --root "$d" 2>&1); check "an empty tree is below the default floor" $? 1 "$out" "0 inputs read"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
