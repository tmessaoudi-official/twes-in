#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/refusals-said.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A template whose field carries a rule, and what $2 puts beside it.
field() { printf '<mat-form-field>\n  <input\n    matInput\n    required\n    formControlName="name"\n  />\n%b</mat-form-field>\n' "${2:-}" > "$1"; }
SAID='  <mat-error>x</mat-error>\n'
repo() {
  local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/x"
  for ((i = 0; i < 14; i++)); do field "$d/web/src/app/x/f$i.html" "$SAID"; done
  git -C "$d" add -A; echo "$d"
}
echo "refusals-said gate"
d=$(repo)
out=$(bash "$GATE" --root "$d" 2>&1); check "fields saying their refusal pass" $? 0 "$out" "OK — 14 files"
field "$d/web/src/app/x/f3.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a required field with nothing said is named with its line" $? 1 "$out" "x/f3.html:2 refuses a value without a word"
d=$(repo); printf '<mat-form-field>\n  <input matInput type="number" [max]="most" />\n</mat-form-field>\n' >> "$d/web/src/app/x/f4.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a word said for one field does not answer for another" $? 1 "$out" "x/f4.html:10"
d=$(repo); printf '<mat-form-field>\n  <input matInput maxlength="40" />\n</mat-form-field>\n' > "$d/web/src/app/x/g.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a length the browser stops typing at is no rule to say" $? 0 "$out" "OK"
d=$(repo); printf '<p role="alert">x</p>\n<button [disabled]="!valid()">x</button>\n' > "$d/web/src/app/x/f5.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a button disabled on a wrong value passes beside a word said" $? 0 "$out" "OK — 15"
printf '<button [disabled]="!valid()">x</button>\n' > "$d/web/src/app/x/f5.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a button disabled on a wrong value with nothing said is named" $? 1 "$out" "x/f5.ts:1"
printf '<button [disabled]="busy()">x</button>\n' > "$d/web/src/app/x/f5.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a button disabled while busy says nothing to change" $? 0 "$out" "OK — 14"
field "$d/web/src/app/x/f6.html" '  <!-- refusal-said: under the row -->\n'; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a field naming where it is said further on passes" $? 0 "$out" "OK"
d=$(repo); printf '<mat-form-field>\n  <input\n    matInput\n    [value]="v()"\n    [attr.aria-invalid]="bad()"\n  />\n  <mat-error>x</mat-error>\n</mat-form-field>\n' > "$d/web/src/app/x/g.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "aria-invalid set by hand on a field leaves it grey and is named" $? 1 "$out" "x/g.html:2 refuses a value and leaves its field grey"
printf '<mat-form-field>\n  <input matInput [value]="v()" />\n  @if (bad()) {\n    <mat-hint\n      class="text-error"\n      >x</mat-hint\n    >\n  }\n</mat-form-field>\n' > "$d/web/src/app/x/g.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a refusal said as a hint in red is named with its line" $? 1 "$out" "x/g.html:4 refuses a value and leaves its field grey"
printf '<mat-form-field>\n  <input matInput [value]="v()" [appRefused]="bad()" />\n  <mat-error>x</mat-error>\n</mat-form-field>\n' > "$d/web/src/app/x/g.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a field refused through appRefused and mat-error passes" $? 0 "$out" "OK — 14"
d=$(repo); git -C "$d" rm -qf "$d"/web/src/app/x/f[0-4].html
out=$(bash "$GATE" --root "$d" 2>&1); check "far fewer ruled files than written reds rather than passing over nothing" $? 1 "$out" "no longer sees"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
