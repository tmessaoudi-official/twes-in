#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/design-tokens.sh
pass=0; fail=0
# The real tree's floor is a count no fixture reaches; the floor's own case sets one.
export DESIGN_TOKENS_FLOOR=0 DESIGN_TOKENS_HEADINGS_FLOOR=0 DESIGN_TOKENS_CARDS_FLOOR=0
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
d=$(repo); mkdir -p "$d/web/src/app/shared/qr" "$d/web/src/app/shared/settings"; printf '<rect fill="#ffffff" />\n' > "$d/web/src/app/shared/qr/qr-code.ts"; printf "export const DEFAULT_ACCENT = '#1f6feb';\n" > "$d/web/src/app/shared/settings/settings-registry.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "the two named exceptions pass: a QR code's fixed ink and the default accent setting" $? 0 "$out" "OK — 0 files"
d=$(repo); printf '.a { color: #abc; }\n' > "$d/web/src/app/x/new.scss"
out=$(bash "$GATE" --root "$d" 2>&1); check "a file not yet staged is read too" $? 1 "$out" "web/src/app/x/new.scss:1"
d=$(repo); out=$(bash "$GATE" --root "$d" 2>&1); check "an empty tree is reported, not read as a pass over nothing" $? 0 "$out" "OK — 0 files"
tokens() { mkdir -p "$1/web/src"; printf '@theme inline {\n  --color-primary: var(--mat-sys-primary);\n  --color-on-surface: var(--mat-sys-on-surface);\n  --color-surface-container-high: var(--mat-sys-surface-container-high);\n}\n' > "$1/web/src/tailwind.css"; }
d=$(repo); tokens "$d"; printf '<ol>\n  <li [class.bg-surface-container-highest]="!current">x</li>\n</ol>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a colour class naming a token tailwind.css lacks is caught: Tailwind emits nothing for it" $? 1 "$out" "web/src/app/x/a.html:2 unknown colour token surface-container-highest"
d=$(repo); tokens "$d"; printf '<span class="hover:bg-primary text-on-surface/60" [class.bg-surface-container-high]="on">x</span>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "defined tokens pass with a variant, an opacity or a class binding" $? 0 "$out" "OK — 1 files"
accent() { mkdir -p "$1/web/src"; printf '@theme inline {\n  --color-accent: var(--twes-accent);\n  --color-accent-soft: var(--twes-accent-soft);\n  --color-on-accent: var(--twes-on-accent);\n}\n' > "$1/web/src/tailwind.css"; }
d=$(repo); accent "$d"; printf '<a class="bg-accent-soft text-on-accent hover:bg-accent">x</a>\n<b class="text-accent-strong">y</b>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "the accent's roles are read as colour tokens: a declared one passes, an undeclared one is caught" $? 1 "$out" "web/src/app/x/a.html:2 unknown colour token accent-strong"
# The theme is closed (docs/SPEC.md § 7, 2026-11-02 00:32): a type step, weight, family, radius, shadow, line height,
# tracking or easing a class names must be one tailwind.css declares, or Tailwind draws nothing for it.
scale() {
  mkdir -p "$1/web/src"
  printf '@theme {\n  --text-*: initial;\n  --color-print-ink: #000;\n  --font-mono: monospace;\n  --text-sm: 0.875rem;\n  --text-sm--line-height: 1.4;\n  --font-weight-semibold: 600;\n  --tracking-wide: 0.025em;\n  --leading-tight: 1.25;\n  --radius-lg: 0.5rem;\n  --shadow-sm: 0 1px 3px 0 rgb(0 0 0 / 0.1);\n  --ease-out: cubic-bezier(0, 0, 0.2, 1);\n}\n@theme inline {\n  --font-sans: Inter;\n  --color-primary: var(--mat-sys-primary);\n}\n@theme {\n  --text-title: 1.5rem;\n  --radius-card: 14px;\n}\n' > "$1/web/src/tailwind.css"
}
d=$(repo); scale "$d"; printf '<p\n  class="text-sm font-semibold font-mono font-sans tracking-wide leading-tight leading-4 leading-none rounded-lg rounded-t-lg rounded-full rounded-none rounded-card shadow-sm shadow-none ease-out ease-linear text-title text-center text-end text-ellipsis text-nowrap text-print-ink bg-transparent text-current bg-primary grid-cols-[1fr_2fr] max-w-[50rem]"\n  [class.md:text-sm]="on"\n>x</p>\n' > "$d/web/src/app/x/a.html"; printf "/** The total, rounded half away from zero, with no shadow of doubt. */\nexport const CARD = 'rounded-card shadow-sm text-sm';\n" > "$d/web/src/app/x/a.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "declared steps, built-in keywords, geometry values and a comment's words pass" $? 0 "$out" "OK — 2 files"
d=$(repo); scale "$d"; printf '<p class="text-lg font-bold font-serif tracking-tight leading-loose rounded-xl shadow-lg ease-in">x</p>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a step tailwind.css does not declare is caught, one per namespace" $? 1 "$out" "web/src/app/x/a.html:1 undeclared step text-lg, font-bold, font-serif, tracking-tight, leading-loose, rounded-xl, shadow-lg, ease-in"
d=$(repo); scale "$d"; printf '<div\n  class="flex rounded\n    shadow p-2"\n>x</div>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a bare rounded or shadow over a multi-line class is caught at its own line" $? 1 "$out" "web/src/app/x/a.html:2 undeclared step rounded"
check "  and the shadow on the next" 0 0 "$out" "web/src/app/x/a.html:3 undeclared step shadow"
d=$(repo); scale "$d"; printf "export const C = 'text-[13px] rounded-[3px] font-[650] leading-[1.1] tracking-[0.1em] duration-[250ms] shadow-[0_1px_2px] accent-[var(--x)]';\n" > "$d/web/src/app/x/a.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "an arbitrary value for a type, radius, shadow, motion or colour is caught" $? 1 "$out" "web/src/app/x/a.ts:1 arbitrary value text-[13px], rounded-[3px], font-[650], leading-[1.1], tracking-[0.1em], duration-[250ms], shadow-[0_1px_2px], accent-[var(--x)]"
d=$(repo); scale "$d"; printf '<b class="text-black bg-white border-gray-200 text-red-600">x</b>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a colour from Tailwind's own palette is caught: the palette is closed" $? 1 "$out" "web/src/app/x/a.html:1 undeclared step text-black, bg-white, border-gray-200, text-red-600"
d=$(repo); scale "$d"; printf '<b [style.font-size.px]="size" [style.border-radius]="r" [style.width.px]="w" [style.background-color]="tone">x</b>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a style binding of a font or a radius is caught; geometry and colour tokens are not" $? 1 "$out" "web/src/app/x/a.html:1 style binding font-size, border-radius"
d=$(repo); scale "$d"; printf '<p class="text-sm">x</p>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(DESIGN_TOKENS_FLOOR=5 bash "$GATE" --root "$d" 2>&1); check "fewer classes read than the floor reds: a parser finding nothing is not a pass" $? 1 "$out" "1 type, radius and shadow classes read, below the floor of 5"
# A stylesheet reads the same steps: a literal type size, weight, family, radius, shadow or motion value is refused in
# web/src/styles.scss and in a component's stylesheet, outside @font-face and the custom properties that define them.
sheet() { mkdir -p "$1/web/src"; printf '%s\n' "$2" > "$1/web/src/styles.scss"; }
d=$(repo); scale "$d"; sheet "$d" '@font-face {
  font-family: "Inter Variable";
  font-weight: 100 900;
}
:root {
  --twes-motion-standard: 120ms;
}
.a {
  font-size: var(--text-sm);
  font-weight: var(--font-weight-semibold) !important;
  border-radius: var(--radius-lg) var(--radius-lg) 0 0;
  border-radius: 50%;
  box-shadow:
    inset 3px 0 0 var(--twes-accent-text),
    var(--twes-shadow-1);
  transition: background-color var(--twes-motion-standard) var(--twes-ease-standard);
  animation: none;
  font-family: inherit;
}
.icon {
  font-family: "Material Symbols Outlined";
}'; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a stylesheet reading steps, geometry and keywords passes; @font-face and token definitions are not read" $? 0 "$out" "OK"
d=$(repo); scale "$d"; sheet "$d" '.a {
  font-size: 0.875rem;
  font-weight: 650;
  font-family:
    Manrope, sans-serif;
  border-radius: 10px;
  box-shadow: 0 1px 2px rgb(0 0 0 / 0.1);
  transition: opacity 200ms ease;
  animation: pulse 2.4s ease-out;
}
@include mat.form-field-overrides((outlined-container-shape: 10px, header-headline-weight: 600));'; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a literal type size in the global stylesheet is caught, by line" $? 1 "$out" "web/src/styles.scss:2 literal font-size"
for want in "styles.scss:3 literal font-weight" "styles.scss:4 literal font-family" "styles.scss:6 literal border-radius" "styles.scss:7 literal box-shadow" "styles.scss:8 literal transition" "styles.scss:9 literal animation" "styles.scss:11 literal outlined-container-shape, header-headline-weight"; do
  check "  and $want" 0 0 "$out" "$want"
done
d=$(repo); scale "$d"; printf '.b { border-radius: 6px; }\n' > "$d/web/src/app/x/b.scss"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a component stylesheet is read the same way" $? 1 "$out" "web/src/app/x/b.scss:1 literal border-radius"
d=$(repo); scale "$d"; printf '%s\n' '// font-size: 12px in a comment is not a style' '@Component({' '  styles: `' '    :host { font-size: var(--text-xs); }' '    .dot { border-radius: 9999px; }' '  `,' '})' 'const size = { "font-size": 12 };' > "$d/web/src/app/x/c.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a component's inline styles are read, by line, and nothing else of its file" $? 1 "$out" "web/src/app/x/c.ts:5 literal border-radius"
check "  and only that line" "$(grep -c "c.ts:" <<<"$out")" 1 "$out" ""
# Primary is Material's component chrome; a word, a link or an icon read as text is the accent text, which reads on
# every ground of the theme, and a tint is one of the theme's derived grounds.
d=$(repo); scale "$d"; printf '<a class="font-semibold text-primary">x</a>\n<b [class.text-primary]="on">y</b>\n<i class="bg-primary fill-primary hover:bg-primary text-primary/60">z</i>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a text-primary class is caught, in a class list and in a class binding" $? 1 "$out" "web/src/app/x/a.html:1 primary where an accent role is meant: text-primary"
check "  and in the binding on the next line" 0 0 "$out" "web/src/app/x/a.html:2 primary where an accent role is meant: text-primary"
check "  and with an opacity, while chrome on primary passes" "$(grep -c 'a.html:3' <<<"$out")" 1 "$out" "a.html:3 primary where an accent role is meant: text-primary"
d=$(repo); scale "$d"; sheet "$d" '.a { color: var(--mat-sys-primary); }
.b:hover { background-color: color-mix(in oklch, var(--mat-sys-primary) 5%, transparent); }
.c { background-color: var(--mat-sys-primary); color: var(--mat-sys-on-primary); outline-color: var(--twes-focus); }
.d { color: var(--twes-accent-text); --mat-button-text-label-text-color: var(--mat-sys-primary); selected-icon-color: var(--mat-sys-primary); }'; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "primary as a text colour in the global stylesheet is caught" $? 1 "$out" "web/src/styles.scss:1 primary where an accent role is meant: color"
check "  and primary mixed into a ground" 0 0 "$out" "web/src/styles.scss:2 primary where an accent role is meant: color-mix"
check "  while a fill on primary, its ink and the accent roles pass" "$(grep -cE 'styles.scss:(3|4)' <<<"$out")" 0 "$out" ""
d=$(repo); scale "$d"; printf '.x {\n  border-color: color-mix(in srgb, var(--mat-sys-primary) 40%%, var(--mat-sys-surface));\n}\n' > "$d/web/src/app/x/b.scss"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a component stylesheet mixing primary is caught too" $? 1 "$out" "web/src/app/x/b.scss:2 primary where an accent role is meant: color-mix"
# A heading takes its role's step and a card its kind's corner: a raw size on an h1 or h2, or a raw radius on a
# mat-card or a twes-card, is refused even where the step is declared, so the roles stay one canon.
d=$(repo); scale "$d"; printf '<section>\n  <h1\n    class="m-0 text-3xl font-semibold"\n    data-testid="t"\n  >x</h1>\n  <h2 class="lg:text-sm text-print-ink">y</h2>\n  <h2 class="text-title text-print-ink text-start">z</h2>\n</section>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a raw text size on an h1 spread over lines is caught at the tag" $? 1 "$out" "web/src/app/x/a.html:2 heading with a raw text size: text-3xl"
check "  and on an h2 behind a variant, a declared step included" 0 0 "$out" "web/src/app/x/a.html:6 heading with a raw text size: lg:text-sm"
check "  while a role with a colour and an alignment passes" "$(grep -c 'a.html:7' <<<"$out")" 0 "$out" ""
d=$(repo); scale "$d"; printf '@Component({\n  template: `\n    <h2 mat-dialog-title class="flex-1 text-sm">x</h2>\n  `,\n})\nexport class A {}\n' > "$d/web/src/app/x/a.ts"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a raw text size on a heading of an inline template is caught" $? 1 "$out" "web/src/app/x/a.ts:3 heading with a raw text size: text-sm"
d=$(repo); scale "$d"; printf '<mat-card\n  class="rounded-lg"\n>x</mat-card>\n<div class="twes-card rounded-lg p-4">y</div>\n<mat-card class="rounded-card">z</mat-card>\n<div class="rounded-lg border p-4">w</div>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "a raw radius on a mat-card is caught at the tag" $? 1 "$out" "web/src/app/x/a.html:1 card with a raw radius: rounded-lg"
check "  and on a twes-card" 0 0 "$out" "web/src/app/x/a.html:4 card with a raw radius: rounded-lg"
check "  while the card's own corner and a plain box pass" "$(grep -cE 'a.html:(5|6)' <<<"$out")" 0 "$out" ""
d=$(repo); scale "$d"; printf '<h1 class="text-title">x</h1>\n<h2 mat-dialog-title>y</h2>\n<mat-card>z</mat-card>\n' > "$d/web/src/app/x/a.html"; git -C "$d" add -A
out=$(bash "$GATE" --root "$d" 2>&1); check "headings in a role and cards are counted" $? 0 "$out" "2 headings in a role, 1 cards"
out=$(DESIGN_TOKENS_HEADINGS_FLOOR=3 bash "$GATE" --root "$d" 2>&1); check "fewer headings in a role than the floor reds: losing the discovery is no pass" $? 1 "$out" "2 headings in a role, below the floor of 3"
out=$(DESIGN_TOKENS_CARDS_FLOOR=2 bash "$GATE" --root "$d" 2>&1); check "fewer cards than the floor reds" $? 1 "$out" "1 cards read, below the floor of 2"
echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
