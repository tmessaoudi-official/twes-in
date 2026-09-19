#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/design-tokens.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
repo() { local d; d=$(mktemp -d); git -C "$d" init -q; mkdir -p "$d/web/src/app/x" "$d/web/src/app/shared/theme"; echo "$d"; }
echo "design-tokens gate"
d=$(repo); printf '.card {\n  padding: 8px;\n  color: #1f6feb;\n}\n' > "$d/web/src/app/x/a.scss"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a hex colour in a component stylesheet is caught, by file and line" $? 1 "$out" "web/src/app/x/a.scss:3"
d=$(repo); printf "export const TONE = 'rgba(0, 0, 0, 0.4)';\n" > "$d/web/src/app/x/a.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a colour function in TypeScript is caught" $? 1 "$out" "web/src/app/x/a.ts:1"
d=$(repo); printf '<svg><rect fill="#000" /></svg>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a hex colour in a template attribute is caught" $? 1 "$out" "web/src/app/x/a.html:1"
d=$(repo); printf '<p>\n  <span style="margin-top: 4px">x</span>\n</p>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a static inline style is caught" $? 1 "$out" "web/src/app/x/a.html:2"
d=$(repo); printf '<input #face #bad />\n<span [style.width.px]="w" [style.background]="tone">x</span>\n' > "$d/web/src/app/x/a.html"; printf '.a { color: var(--twes-ink); background: color-mix(in oklch, var(--a) 20%%, transparent); }\n' > "$d/web/src/app/x/a.scss"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "template references, style bindings and tokens are not colours" $? 0 "$out" "OK — 2 files"
d=$(repo); printf ':root { --twes-ink: #111; }\n' > "$d/web/src/app/shared/theme/t.scss"; mkdir -p "$d/web/src"; printf ':root { --a: oklch(0.6 0.1 250); }\n' > "$d/web/src/styles.scss"; printf "const c = '#fff';\n" > "$d/web/src/app/x/a.spec.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "the theme, the global stylesheet and specs define or test tokens and are not read" $? 0 "$out" "OK — 0 files"
d=$(repo); mkdir -p "$d/web/src/app/auth" "$d/web/src/app/shared/settings"; printf '<rect fill="#ffffff" />\n' > "$d/web/src/app/auth/qr-code.ts"; printf "export const DEFAULT_ACCENT = '#1f6feb';\n" > "$d/web/src/app/shared/settings/settings-registry.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "the two named exceptions pass: a QR code's fixed ink and the default accent setting" $? 0 "$out" "OK — 0 files"
d=$(repo); printf '.a { color: #abc; }\n' > "$d/web/src/app/x/new.scss"
out=$(bash "$GATE" --root "$d" 2>&1); check "a file not yet staged is read too" $? 1 "$out" "web/src/app/x/new.scss:1"
d=$(repo); out=$(bash "$GATE" --root "$d" 2>&1); check "an empty tree is reported, not read as a pass over nothing" $? 0 "$out" "OK — 0 files"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
