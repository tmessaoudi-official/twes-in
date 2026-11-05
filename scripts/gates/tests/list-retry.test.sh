#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/list-retry.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
repo() { local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/x"; echo "$d"; }
echo "list-retry gate"
d=$(repo); printf '<app-data-list\n  [rows]="rows()"\n  [failed]="readFailed()"\n  (retry)="readAgain()"\n/>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "a list reading again on retry passes" $? 0 "$out" "OK — 1 "
printf '<app-data-list [failed]="f()" (queryChange)="ask($event)"></app-data-list>\n' > "$d/web/src/app/x/b.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "a paged list, asking its page again, passes" $? 0 "$out" "OK — 2 "
printf '<p>x</p>\n<app-data-list\n  [rows]="rows()"\n  [failed]="facade.readFailed()"\n  testId="t"\n>\n  <ng-template (retry)="no()"></ng-template>\n</app-data-list>\n' > "$d/web/src/app/x/c.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "a list whose failure has no way to read again is named, a binding inside it not counting" $? 1 "$out" "x/c.html:2"
rm "$d/web/src/app/x/c.html"; git -C "$d" add -A
printf '<app-data-list [rows]="rows()" testId="t" />\n' > "$d/web/src/app/x/c.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "a list that cannot fail is not asked" $? 0 "$out" "OK — 2 "
out=$(bash "$GATE" --root "$d" --floor 3 2>&1); check "fewer failing lists than the floor reds" $? 1 "$out" "floor"
printf '<app-data-list [failed]="f()" [rowTestId]="(row) => row.id" (retry)="again()" />\n' > "$d/web/src/app/x/c.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "an arrow inside a value does not end the tag" $? 0 "$out" "OK — 3 "
printf '<app-data-list [failed]="facade.error() !== null" (retry)="again()" />\n' > "$d/web/src/app/x/d.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "a list failing on the facade's whole error, which a refused save sets too, is named" $? 1 "$out" "x/d.html:1"
printf '<app-data-list [failed]="readFailed() || error() !== null" (retry)="again()" />\n' > "$d/web/src/app/x/d.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "any error() in the failure is named" $? 1 "$out" "refused save"
printf '<app-data-list [failed]="readFailed()" [rows]="errors()" (retry)="again()" />\n' > "$d/web/src/app/x/d.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "an error read by another binding of the tag is not the failure's" $? 0 "$out" "OK — 4 "
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
