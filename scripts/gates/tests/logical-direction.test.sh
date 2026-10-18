#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/logical-direction.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the web's: templates and components under src/app, the global styles beside it.
tree() {
  local d; d=$(mktemp -d)
  mkdir -p "$d/web/src/app/shared" "$d/web/src/app/api"
  printf '<div class="flex gap-2 ps-4 pe-2 ms-auto text-start border-s rounded-e-lg inset-s-0">x</div>\n' > "$d/web/src/app/page.html"
  printf "@Component({ template: \`<p class=\"me-2\">x</p>\` })\nexport class Page {\n  readonly left = 3;\n  compare(left: number, right: number) { return left - right; }\n}\n" > "$d/web/src/app/page.ts"
  printf '.twes-x {\n  margin-inline-start: 1rem;\n  inset-inline-end: 0;\n}\n' > "$d/web/src/styles.scss"
  printf '<mat-icon aria-hidden="true" class="mat-icon-rtl-mirror">arrow_back</mat-icon>\n' > "$d/web/src/app/back.html"
  echo "$d"
}
run() { bash "$GATE" --root "$1" 2>&1; }
echo "logical-direction gate"

d=$(tree); out=$(run "$d"); check "a tree written in logical directions passes" $? 0 "$out" "OK"

for class in pl-4 pr-2 ml-auto -mr-1 left-0 right-3 text-left text-right border-l border-r-2 rounded-l-lg rounded-tr-md float-left scroll-ml-4 -translate-x-1/2; do
  d=$(tree); printf '<div class="flex %s">x</div>\n' "$class" > "$d/web/src/app/old.html"
  out=$(run "$d"); check "the physical class $class is refused" $? 1 "$out" "old.html:1"
done

d=$(tree); printf '<div class="max-lg:pr-4 lg:border-l">x</div>\n' > "$d/web/src/app/old.html"
out=$(run "$d"); check "a physical class behind a variant is refused" $? 1 "$out" "old.html:1"

d=$(tree); printf "export const css = 'flex pl-2';\n" > "$d/web/src/app/classes.ts"
out=$(run "$d"); check "a physical class in TypeScript is refused" $? 1 "$out" "classes.ts:1"

for rule in 'margin-left: 1rem;' 'padding-right: 0;' 'border-left: 1px solid;' 'left: 0;' '  right: 2px;' 'text-align: right;' 'float: left;' 'transform: translateX(1.5rem);' 'transform: translate3d(1px, 0, 0);'; do
  d=$(tree); printf '.twes-y {\n%s\n}\n' "$rule" > "$d/web/src/app/shared/y.scss"
  out=$(run "$d"); check "the physical rule '$rule' is refused" $? 1 "$out" "y.scss:2"
done

for rule in 'padding: 0.25rem 0.5rem 0.25rem 1rem;' 'margin: 0 1rem 0 0;' 'border-radius: 1rem 0 0 1rem;' 'border-radius: 0 1rem;'; do
  d=$(tree); printf '.twes-y {\n  %s\n}\n' "$rule" > "$d/web/src/app/shared/y.scss"
  out=$(run "$d"); check "the shorthand '$rule', its sides told apart, is refused" $? 1 "$out" "y.scss:2"
done

d=$(tree); printf '.twes-y {\n  padding: 0.25rem calc(1rem + 2px) 0.5rem calc(1rem + 2px);\n  margin: 0 auto;\n  border-radius: 1rem 1rem 0 0;\n}\n' > "$d/web/src/app/shared/y.scss"
out=$(run "$d"); check "a shorthand giving both sides the same passes" $? 0 "$out" "OK"

d=$(tree); printf '.twes-z {\n  left: 0;\n}\n' >> "$d/web/src/styles.scss"
out=$(run "$d"); check "the global styles are read too" $? 1 "$out" "styles.scss:6"

d=$(tree); printf '<div class="pl-4" dir="ltr">x</div> <!-- physical-direction: a plan is drawn in its own space -->\n' > "$d/web/src/app/plan.html"
out=$(run "$d"); check "a line saying why may stay physical" $? 0 "$out" "OK"

d=$(tree); printf '<div class="pl-4">x</div> <!-- physical-direction: -->\n' > "$d/web/src/app/plan.html"
out=$(run "$d"); check "a reason left empty is no reason" $? 1 "$out" "plan.html:1"

d=$(tree); printf '<div class="flex">left and right are words here</div>\n<p class="text-lg leftover">x</p>\n' > "$d/web/src/app/words.html"
out=$(run "$d"); check "words that are no classes pass" $? 0 "$out" "OK"

for icon in arrow_back arrow_forward chevron_left chevron_right send; do
  d=$(tree); printf '<mat-icon aria-hidden="true">%s</mat-icon>\n' "$icon" > "$d/web/src/app/icon.html"
  out=$(run "$d"); check "$icon without the mirror class is refused" $? 1 "$out" "icon.html:1"
done

d=$(tree); printf '<p>x</p>\n<mat-icon\n  aria-hidden="true"\n  class="!size-4"\n  >chevron_right</mat-icon\n>\n' > "$d/web/src/app/icon.html"
out=$(run "$d"); check "an icon element spread over lines is read whole, at its opening line" $? 1 "$out" "icon.html:2"

d=$(tree); printf '<mat-icon\n  aria-hidden="true"\n  class="mat-icon-rtl-mirror !size-4"\n  >chevron_right</mat-icon\n>\n' > "$d/web/src/app/icon.html"
out=$(run "$d"); check "a spread-out icon carrying the class passes" $? 0 "$out" "OK"

d=$(tree); printf '<mat-icon aria-hidden="true">search</mat-icon>\n' > "$d/web/src/app/icon.html"
out=$(run "$d"); check "an icon that points no way needs no mirror" $? 0 "$out" "OK"

d=$(tree); printf "it('pads', () => expect(el.className).toContain('pl-4'));\n" > "$d/web/src/app/page.spec.ts"
printf "export const x = 'pl-4';\n" > "$d/web/src/app/api/types.gen.ts"
out=$(run "$d"); check "specs and the generated API types are left out" $? 0 "$out" "OK"

d=$(tree); chmod 000 "$d/web/src/app/page.html"
out=$(run "$d"); status=$?; chmod 644 "$d/web/src/app/page.html"; check "a file that cannot be read is refused, not read as clean" "$status" 1 "$out" "page.html: cannot be read"

d=$(tree); mkdir "$d/web/src/app/closed"; chmod 000 "$d/web/src/app/closed"
out=$(run "$d"); status=$?; chmod 755 "$d/web/src/app/closed"; check "a folder grep cannot enter is refused, not read as clean" "$status" 1 "$out" "could not search"

d=$(tree); rm -r "$d/web/src/app"
out=$(run "$d"); check "a missing web/src/app is refused, not skipped" $? 1 "$out" "found no web/src/app"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
