#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/coming-gated.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
repo() {
  local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/x" "$d/web/src/app/shell"
  echo "$d"
}
echo "coming-gated gate"
d=$(repo); printf '<span class="twes-soon">{{ "shell.soon" | translate }}</span>\n' > "$d/web/src/app/x/a.html"
printf 'export class A { readonly show = inject(ThemeFacade).showComing; }\n' > "$d/web/src/app/x/a.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a template drawing the chip whose component reads showComing passes" $? 0 "$out" "OK — 1 "
printf 'export class A { }\n' > "$d/web/src/app/x/a.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a chip whose component never reads showComing is named" $? 1 "$out" "x/a.html"
d=$(repo); printf '@Component({ template: `<span>{{ "shell.soon" | translate }}</span>` })\nexport class B { }\n' > "$d/web/src/app/x/b.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "an inline template drawing the chip without showComing is named" $? 1 "$out" "x/b.ts"
d=$(repo); printf '<span>{{ "shell.soon" | translate }}</span>\n' > "$d/web/src/app/shell/coming-page.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "the « En construction » page itself, reached on purpose, is exempt" $? 0 "$out" "OK"
d=$(repo); printf '<span>{{ "shell.soon" | translate }}</span>\n' > "$d/web/src/app/x/a.spec.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a spec naming the key is not a site" $? 1 "$out" "no template"
d=$(repo); git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a tree with no chip at all reds rather than passing over nothing" $? 1 "$out" "no template"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
