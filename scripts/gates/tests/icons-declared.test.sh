#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/icons-declared.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A registry of four icons, each named by the app in one of the shapes the gate reads.
repo() {
  local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/shared/icons" "$d/web/src/app/x"
  printf "export const ICONS = [\n  'add',\n  'check_circle',\n  'home',\n  'radio_button_unchecked',\n] as const;\n" > "$d/web/src/app/shared/icons/icons.ts"
  printf '<mat-icon aria-hidden="true">\n  add\n</mat-icon>\n<mat-icon>{{\n  done ? '"'check_circle'"' : '"'radio_button_unchecked'"'\n}}</mat-icon>\n' > "$d/web/src/app/x/a.html"
  printf "export const NAV = [{ route: '/', icon: 'home' }];\n" > "$d/web/src/app/x/b.ts"
  git -C "$d" add -A; echo "$d"
}
run() { bash "$GATE" --root "$1" --floor 3 2>&1; }
echo "icons-declared gate"
d=$(repo); out=$(run "$d"); check "every icon a template writes is declared, and every declared one named, passes" $? 0 "$out" "OK — 4 icons"
d=$(repo); printf '<mat-icon>hom</mat-icon>\n' >> "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(run "$d"); check "a template icon the registry does not declare is named (it would show as letters)" $? 1 "$out" "hom"
d=$(repo); printf "<mat-icon>{{ kind === 'success' ? 'add' : 'lock' }}</mat-icon>\n" >> "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(run "$d"); check "a ternary's result is read, an operand compared against is not" $? 1 "$out" "lock"
[[ "$out" != *success* ]] && { pass=$((pass+1)); echo "  ok   the compared operand is not taken for an icon"; } || { fail=$((fail+1)); echo "  FAIL the compared operand was taken for an icon"; echo "$out" | sed 's/^/       /'; }
d=$(repo); printf "@Component({ template: \`<mat-icon>visibility</mat-icon>\` })\nexport class C {}\n" > "$d/web/src/app/x/c.ts"; git -C "$d" add -A
out=$(run "$d"); check "an inline component template is read too" $? 1 "$out" "visibility"
d=$(repo); printf '<mat-icon>visibility</mat-icon>\n' > "$d/web/src/app/x/a.spec.ts"; git -C "$d" add -A
out=$(run "$d"); check "a spec is not the app" $? 0 "$out" "OK"
d=$(repo); printf "export const NAV = [{ route: '/' }];\n" > "$d/web/src/app/x/b.ts"; git -C "$d" add -A
out=$(run "$d"); check "a declared icon nothing names any more is named" $? 1 "$out" "home"
d=$(repo); printf "export const ICONS = [\n  'home',\n  'add',\n  'check_circle',\n  'radio_button_unchecked',\n] as const;\n" > "$d/web/src/app/shared/icons/icons.ts"; git -C "$d" add -A
out=$(run "$d"); check "a registry out of alphabetical order is refused" $? 1 "$out" "alphabetical"
d=$(repo); out=$(bash "$GATE" --root "$d" --floor 10 2>&1); check "fewer template icons than the floor reds rather than passing over nothing" $? 1 "$out" "floor"
d=$(repo); printf 'export const OTHER = [];\n' > "$d/web/src/app/shared/icons/icons.ts"; git -C "$d" add -A
out=$(run "$d"); check "a registry that yields no icon reds" $? 1 "$out" "no icon"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
