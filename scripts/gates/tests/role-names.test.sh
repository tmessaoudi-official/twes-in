#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/role-names.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
repo() { local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/x"; echo "$d"; }
echo "role-names gate"
d=$(repo); printf '<p>{{ company.role | roleKey | translate }}</p>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "a role named through roleKey passes" $? 0 "$out" "OK — 1 "
printf "<p>{{ 'roles.' + company.role | translate }}</p>\n" > "$d/web/src/app/x/b.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "a template building a roles. key is named" $? 1 "$out" "x/b.html:1"
printf "@Component({ template: \`<p>{{ 'roles.'+ m.role | translate }}</p>\` }) export class B {}\n" > "$d/web/src/app/x/b.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "spacing does not hide it" $? 1 "$out" "x/b.html:1"
rm "$d/web/src/app/x/b.html"
printf "@Component({ template: \`<p>{{ 'roles.' + m.role | translate }}</p>\` }) export class C {}\n" > "$d/web/src/app/x/c.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "an inline template is read too" $? 1 "$out" "x/c.ts:1"
printf "it('x', () => { expect(\"'roles.' + r\").toBe(''); });\n" > "$d/web/src/app/x/c.spec.ts"; rm "$d/web/src/app/x/c.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --floor 1 2>&1); check "a spec is not read" $? 0 "$out" "OK"
out=$(bash "$GATE" --root "$d" --floor 2 2>&1); check "fewer roleKey uses than the floor reds" $? 1 "$out" "floor"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
