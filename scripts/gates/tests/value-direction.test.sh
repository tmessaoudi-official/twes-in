#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/value-direction.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
repo() { local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/x"; echo "$d"; }
echo "value-direction gate"
d=$(repo); printf '<td class="truncate"><span class="twes-value" dir="auto">{{ row.name }}</span></td>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a value in its own box passes" $? 0 "$out" "OK — 1 twes-value"
printf '<span class="grow truncate">{{ shape.name }}</span>\n' > "$d/web/src/app/x/b.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a typed value cut by truncate is caught, by file and line" $? 1 "$out" "web/src/app/x/b.html:1 {{ shape.name }}"
d=$(repo); printf '\n<span\n  class="block truncate"\n  data-testid="n"\n>{{\n  current.name\n}}</span>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 0 2>&1); check "a tag and a value spread over lines are caught at the tag's line" $? 1 "$out" "a.html:2 {{ current.name }}"
d=$(repo); printf '<span class="truncate">{{ current()?.name ?? (%s | translate) }}</span>\n' "'company.choose'" > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 0 2>&1); check "a value that falls back on a text is still a value" $? 1 "$out" "a.html:1"
d=$(repo); printf '<span class="truncate">{{ %s + company.role | translate }}</span>\n<span class="truncate">{{ %s | translate: { n: 2 } }}</span>\n' "'roles.'" "'x.y'" > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 0 2>&1); check "a text of the screen's own reads its way and passes" $? 0 "$out" "OK — 0 twes-value"
d=$(repo); printf '<span class="twes-value">{{ row.name }}</span>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 0 2>&1); check "a twes-value without dir=auto is caught" $? 1 "$out" "without dir=\"auto\""
d=$(repo); printf '<span class="twes-value" dir="ltr">{{ row.name }}</span>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 0 2>&1); check "a twes-value with a direction forced is caught too" $? 1 "$out" "a.html:1 <span>"
d=$(repo); printf "@Component({ template: \`<span class=\"truncate\">{{ chosen().label }}</span>\` })\nexport class A {}\n" > "$d/web/src/app/x/a.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 0 2>&1); check "an inline template in a component is read too" $? 1 "$out" "web/src/app/x/a.ts:1 {{ chosen().label }}"
d=$(repo); printf '<span class="truncate">{{ row.name }}</span>\n' > "$d/web/src/app/x/a.spec.ts"; printf '<span class="twes-value" dir="auto">{{ a }}</span>\n' > "$d/web/src/app/x/b.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "specs are not read" $? 0 "$out" "OK — 1 twes-value"
d=$(repo); printf '<span class="truncate">Total {{ n }}</span>\n<span class="truncate-x">{{ n }}</span>\n<span class="twes-value" dir="auto">{{ a }}</span>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a text around a value and a class merely named like it are left alone" $? 0 "$out" "OK — 1 twes-value"
out=$(bash "$GATE" --root "$d" --min 2 2>&1); check "fewer than the floor is a failure, not a pass" $? 1 "$out" "fewer than the 2 expected"
d=$(repo); out=$(bash "$GATE" --root "$d" 2>&1); check "an empty tree is below the default floor" $? 1 "$out" "0 twes-value found"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
