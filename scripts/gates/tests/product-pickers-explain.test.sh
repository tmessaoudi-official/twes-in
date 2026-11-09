#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/product-pickers-explain.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: a line's product picker, a descriptor form's and a list filter's, each explaining.
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/web/src/app/things"
  printf '<div>\n  <app-pick-field\n    testId="line-product"\n    [search]="searchProducts"\n    [explainNone]="explainProducts"\n    (picked)="take($event)"\n  />\n  <app-pick-field testId="customer" [search]="searchCustomers" />\n</div>\n' > "$d/web/src/app/things/thing-lines.html"
  printf '<app-descriptor-form\n  [pickers]="{ productId: { search: searchProducts, explainNone: explainProducts, value: shown() } }"\n/>\n' > "$d/web/src/app/things/thing-form.html"
  printf "export class ThingsPage {\n  protected readonly pickSources = {\n    product: {\n      search: (words) => this.pickProducts({ words }),\n      byIds: (ids) => this.pickProducts({ ids }),\n      explainNone: (words) => this.explainProducts(words),\n    },\n  };\n}\n" > "$d/web/src/app/things/things-page.ts"
  echo "$d"
}
run() { bash "$GATE" --root "$1" --floor "${2:-1}" 2>&1; }
echo "product-pickers-explain gate"

d=$(tree); out=$(run "$d" 3); check "three product pickers explaining pass, a customer picker is none of them" $? 0 "$out" "OK — 3 product pickers"

d=$(tree); sed -i '/\[explainNone\]/d' "$d/web/src/app/things/thing-lines.html"
out=$(run "$d"); check "a picker field searching products without explainNone is refused at its tag" $? 1 "$out" "thing-lines.html:2"

d=$(tree); sed -i 's/ explainNone: explainProducts,//' "$d/web/src/app/things/thing-form.html"
out=$(run "$d"); check "a descriptor form's product picker without explainNone is refused" $? 1 "$out" "thing-form.html:2"

d=$(tree); sed -i '/explainNone: (words)/d' "$d/web/src/app/things/things-page.ts"
out=$(run "$d"); check "a list filter searching products without explainNone is refused" $? 1 "$out" "things-page.ts:4"

# A descriptor form's entry as prettier lays it out, one property a line.
d=$(tree); printf '<app-descriptor-form\n  [pickers]="{\n    productId: {\n      search: searchProducts,\n      explainNone: explainProducts,\n      value: shown(),\n    },\n  }"\n/>\n' > "$d/web/src/app/things/thing-form.html"
out=$(run "$d" 3); check "a descriptor form's entry over several lines is read whole" $? 0 "$out" "OK — 3"

d=$(tree); printf '<app-descriptor-form\n  [pickers]="{\n    productId: {\n      search: searchProducts,\n      value: shown(),\n    },\n    otherId: { search: searchProducts, explainNone: explainProducts },\n  }"\n/>\n' > "$d/web/src/app/things/thing-form.html"
out=$(run "$d"); check "an entry over several lines ends at its brace, never taking the next one's" $? 1 "$out" "thing-form.html:4"

d=$(tree); sed -i '/explainNone: (words)/d' "$d/web/src/app/things/things-page.ts"
printf "export class Other {\n  sources = {\n    product: { search: (words) => this.pickProducts({ words }) },\n  };\n}\n" > "$d/web/src/app/things/other.ts"
sed -i 's/{ search: (words) => this.pickProducts({ words }) }/{ search: (words) => this.pickProducts({ words }) } \/\/ explain-none: a filter of history, every product offered/' "$d/web/src/app/things/other.ts"
out=$(run "$d"); check "a list filter whose line says why passes, the other is still named" $? 1 "$out" "things-page.ts:4"

d=$(tree); printf '<app-pick-field\n  testId="x"\n  [search]="searchProducts"\n  explain-none: the code itself is what is searched\n/>\n' > "$d/web/src/app/things/scan.html"
out=$(run "$d" 4); check "a picker field whose element says why passes" $? 0 "$out" "OK — 4 product pickers"

d=$(tree); printf '<app-pick-field testId="x" [search]="searchProducts" />\n' > "$d/web/src/app/things/one-line.html"
out=$(run "$d"); check "a picker field on one line is read too" $? 1 "$out" "one-line.html:1"

d=$(tree); printf '<app-pick-field\n  testId="x"\n  [search]="searchProducts"\n  explain-none:\n/>\n' > "$d/web/src/app/things/empty.html"
out=$(run "$d"); check "a reason left empty is no reason" $? 1 "$out" "empty.html:1"

d=$(tree); printf "it('asks', () => '<app-pick-field [search]=\"searchProducts\" />');\n" > "$d/web/src/app/things/thing-lines.spec.ts"
out=$(run "$d" 3); check "specs draw pickers freely" $? 0 "$out" "OK — 3"

d=$(tree); printf "@Component({ template: \`\n  <app-pick-field\n    [search]=\"searchProducts\"\n  />\n\` })\nexport class Card {}\n" > "$d/web/src/app/things/card.ts"
out=$(run "$d"); check "a picker in a component's own template is read" $? 1 "$out" "card.ts:2"

d=$(tree); out=$(run "$d" 4); check "fewer product pickers than the floor is refused" $? 1 "$out" "below the floor of 4"

d=$(tree); rm -r "$d/web/src/app"
out=$(run "$d"); check "a missing web/src/app is refused, not skipped" $? 1 "$out" "found no"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
