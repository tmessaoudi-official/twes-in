#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/icon-buttons-named.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
repo() { local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/x"; echo "$d"; }
echo "icon-buttons-named gate"
d=$(repo); printf '<button mat-icon-button [appLabel]="%s">\n  <mat-icon>close</mat-icon>\n</button>\n' "'x.close' | translate" > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "an icon button named through appLabel passes" $? 0 "$out" "OK — 1 icon-only"
printf '<button\n  mat-icon-button\n  type="button"\n  [attr.aria-label]="%s"\n>\n  <mat-icon>add</mat-icon>\n</button>\n' "'x.add' | translate" > "$d/web/src/app/x/b.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a multi-line icon button named only by aria-label is caught, by file and line" $? 1 "$out" "web/src/app/x/b.html:1"
d=$(repo); printf "@Component({ template: \`<a mat-mini-fab routerLink=\"/\"><mat-icon>home</mat-icon></a>\` })\nexport class A {}\n" > "$d/web/src/app/x/a.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "an inline template in a component is read too" $? 1 "$out" "web/src/app/x/a.ts:1"
d=$(repo); printf '<button mat-icon-button>x</button>\n' > "$d/web/src/app/x/a.spec.ts"; printf '<button mat-button>Enregistrer</button>\n<a mat-list-item>Accueil</a>\n' > "$d/web/src/app/x/b.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "specs and buttons that show words are not icon-only" $? 0 "$out" "OK — 0 icon-only"
d=$(repo); out=$(bash "$GATE" --root "$d" 2>&1); check "an empty tree is reported, not read as a pass over nothing" $? 0 "$out" "OK — 0 icon-only"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
