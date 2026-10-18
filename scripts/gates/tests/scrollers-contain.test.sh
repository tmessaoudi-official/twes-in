#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/scrollers-contain.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
repo() { local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/x"; echo "$d"; }
echo "scrollers-contain gate"
d=$(repo); printf '<div class="relative min-h-0 overflow-y-auto">\n  <span class="sr-only">t</span>\n</div>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a positioned scroller passes" $? 0 "$out" "OK — 1 scroller"
printf '<p>a</p>\n<div class="min-h-0 overflow-y-auto"></div>\n' > "$d/web/src/app/x/b.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "an unpositioned scroller is caught, by file and line" $? 1 "$out" "web/src/app/x/b.html:2 overflow-y-auto"
d=$(repo); printf '<div\n  class="p-4 lg:min-h-0 lg:overflow-y-auto"\n></div>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a scroller behind a breakpoint, on a tag spread over lines, is caught at its line" $? 1 "$out" "a.html:2 lg:overflow-y-auto"
d=$(repo); printf '<div class="lg:relative lg:overflow-y-auto"></div>\n<div class="sticky overflow-x-auto"></div>\n<div class="absolute overflow-scroll"></div>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "relative behind a breakpoint, sticky and absolute all contain" $? 0 "$out" "OK — 3 scrollers"
d=$(repo); printf '<div class="overflow-x-auto"></div>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a sideways scroller is held to it too" $? 1 "$out" "a.html:1 overflow-x-auto"
d=$(repo); printf '<div class="overflow-hidden"></div>\n<div class="overflow-y-auto-ish relative-ish"></div>\n<div class="relative overflow-auto"></div>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a clip and classes merely named like a scroller are not scrollers" $? 0 "$out" "OK — 1 scroller"
d=$(repo); printf "@Component({ template: \`<ul class=\"overflow-y-auto py-1\"></ul>\` })\nexport class A {}\n" > "$d/web/src/app/x/a.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "an inline template in a component is read too" $? 1 "$out" "web/src/app/x/a.ts:1 overflow-y-auto"
d=$(repo); printf '<div class="overflow-y-auto"></div>\n' > "$d/web/src/app/x/a.spec.ts"; printf '<div class="relative overflow-y-auto"></div>\n' > "$d/web/src/app/x/b.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "specs are not read" $? 0 "$out" "OK — 1 scroller"
d=$(repo); printf '.a {\n  position: relative;\n  overflow-y: auto;\n}\n.b { overflow: hidden; }\n' > "$d/web/src/app/x/a.css"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a style rule that positions its scroller passes" $? 0 "$out" "OK — 1 scroller"
printf '.c {\n  display: flex;\n  overflow-x: scroll;\n}\n' > "$d/web/src/app/x/b.css"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a style rule that scrolls unpositioned is caught at its declaration" $? 1 "$out" "web/src/app/x/b.css:3 overflow-x: scroll"
d=$(repo); mkdir -p "$d/web/src"; printf '.a {\n  overflow-y: auto;\n\n  .b {\n    position: relative;\n  }\n}\n' > "$d/web/src/styles.scss"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a nested rule's position does not count for its parent" $? 1 "$out" "web/src/styles.scss:2"
d=$(repo); printf '.a {\n  overflow-y: auto; // scroller-positioned: .layer is fixed\n}\n' > "$d/web/src/app/x/a.scss"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "a declaration saying where it is positioned passes" $? 0 "$out" "OK — 1 scroller"
out=$(bash "$GATE" --root "$d" --min 5 2>&1); check "fewer than the floor is a failure, not a pass" $? 1 "$out" "fewer than the 5 expected"
printf '.b {\n  overflow: auto; // scroller-positioned:\n}\n' > "$d/web/src/app/x/b.scss"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" --min 1 2>&1); check "the marker with no reason is not enough" $? 1 "$out" "b.scss:2"
d=$(repo); out=$(bash "$GATE" --root "$d" 2>&1); check "an empty tree is below the default floor" $? 1 "$out" "0 scrollers found"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
