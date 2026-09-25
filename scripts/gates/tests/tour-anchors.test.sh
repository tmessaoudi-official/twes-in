#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/tour-anchors.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
repo() {
  local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/shared/tour" "$d/web/src/app/x"
  printf "export const TOUR_ANCHORS = [\n  'list-search',\n  'record-bar',\n] as const;\n" > "$d/web/src/app/shared/tour/tour-anchors.ts"
  echo "$d"
}
echo "tour-anchors gate"
d=$(repo); printf '<input\n  data-tour="list-search"\n/>\n' > "$d/web/src/app/x/a.html"
printf "@Component({ template: \`<div data-tour=\"record-bar\"></div>\` })\nexport class B {}\n" > "$d/web/src/app/x/b.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "every declared anchor on an element, in a template or an inline one, passes" $? 0 "$out" "OK — 2 anchors"
rm "$d/web/src/app/x/b.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a declared anchor no element carries any more is named" $? 1 "$out" "record-bar"
d=$(repo); printf '<div data-tour="list-search"></div><div data-tour="record-bar"></div><p data-tour="list-serach"></p>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "an anchor in the app that the list does not declare is named (a typo)" $? 1 "$out" "list-serach"
d=$(repo); printf '<div data-tour="list-search" data-tour="record-bar"></div>\n' > "$d/web/src/app/x/a.spec.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a spec carrying the anchors does not count as the app carrying them" $? 1 "$out" "list-search"
d=$(repo); printf 'export const OTHER = [];\n' > "$d/web/src/app/shared/tour/tour-anchors.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a list that yields no anchor reds rather than passing over nothing" $? 1 "$out" "no anchor"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
