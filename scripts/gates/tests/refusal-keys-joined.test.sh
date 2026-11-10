#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/refusal-keys-joined.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: one screen saying its refusal through the pipe, one still joining its key by hand.
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/web/src/app/things" "$d/web/src/app/others"
  printf "@if (error(); as code) {\n  <p role=\"alert\">\n    {{ code | refusal: 'things.errors' }}\n  </p>\n}\n" > "$d/web/src/app/things/things-page.html"
  printf "@if (error(); as code) {\n  <p role=\"alert\">\n    {{ 'others.errors.' + code | translate }}\n  </p>\n}\n" > "$d/web/src/app/others/others-page.html"
  printf "export function said(code: string): void {\n  feedback.failure(\`others.errors.\${code}\`);\n}\n" > "$d/web/src/app/others/others-page.ts"
  printf "export function said(code: string): string {\n  return refusalKey('things.errors', code).key;\n}\n" > "$d/web/src/app/things/things-page.ts"
  echo "$d"
}
run() { bash "$GATE" --root "$1" --ceiling "${2:-1}" --floor "${3:-1}" --ts-ceiling "${4:-1}" --ts-floor "${5:-1}" 2>&1; }
echo "refusal-keys-joined gate"

d=$(tree); out=$(run "$d"); check "as many joined keys as the ceilings passes" $? 0 "$out" "OK — 2 templates and 2 sources read, 1 refusal keys still joined by hand in templates and 1 in TypeScript"

d=$(tree); printf "<p>{{ 'more.lines.errors.' + refused.code | translate }}</p>\n" >> "$d/web/src/app/things/things-page.html"
out=$(run "$d"); check "one more key joined by hand is refused with its file and line" $? 1 "$out" "web/src/app/things/things-page.html:6"

d=$(tree); printf "<p>{{ 'more.errors.'+code | translate }}</p>\n" >> "$d/web/src/app/things/things-page.html"
out=$(run "$d"); check "a key joined without spaces is counted too" $? 1 "$out" "over the ceiling of 1"

d=$(tree); sed -i "s/'others.errors.' + code | translate/code | refusal: 'others.errors'/" "$d/web/src/app/others/others-page.html"
out=$(run "$d"); check "a key moved to the pipe asks the ceiling to come down" $? 1 "$out" "lower the ceiling in scripts/gates/refusal-keys-joined.sh to 0"

d=$(tree); out=$(run "$d" 0 1); check "at a ceiling of 0, a joined key is refused" $? 1 "$out" "others-page.html:3"

d=$(tree); printf "<p>{{ 'things.labels.' + name | translate }}</p>\n" >> "$d/web/src/app/things/things-page.html"
out=$(run "$d"); check "a key joined for anything but a refusal is not counted" $? 0 "$out" "1 refusal keys"

d=$(tree); printf "@Component({ template: \`{{ 'more.errors.' + code | translate }}\` })\nexport class More {}\n" > "$d/web/src/app/things/more.ts"
out=$(run "$d"); check "one more key joined in TypeScript is refused with its file and line" $? 1 "$out" "web/src/app/things/more.ts:1"

d=$(tree); printf "const key = \`\${prefix}.errors.\${code}\`;\n" >> "$d/web/src/app/things/things-page.ts"
out=$(run "$d"); check "a key joined in a template literal with its prefix computed is counted too" $? 1 "$out" "over the TypeScript ceiling of 1"

d=$(tree); sed -i 's/feedback.failure(`others.errors.${code}`);/feedback.failure(refusalKey("others.errors", code).key);/' "$d/web/src/app/others/others-page.ts"
out=$(run "$d"); check "a TypeScript key moved to refusalKey asks its ceiling to come down" $? 1 "$out" "lower the TypeScript ceiling in scripts/gates/refusal-keys-joined.sh to 0"

d=$(tree); printf "expect(said).toBe(\`others.errors.\${code}\`);\n" > "$d/web/src/app/others/others-page.spec.ts"
out=$(run "$d"); check "a spec naming a key is not counted" $? 0 "$out" "and 1 in TypeScript"

d=$(tree); out=$(run "$d" 1 1 1 3); check "fewer sources than the TypeScript floor is refused" $? 1 "$out" "below the floor of 3"

d=$(tree); out=$(run "$d" 1 3); check "fewer templates than the floor is refused" $? 1 "$out" "below the floor of 3"

d=$(mktemp -d); out=$(bash "$GATE" --root "$d" 2>&1); check "a tree without web/src/app is refused" $? 1 "$out" "found no web/src/app"

echo "$pass passed, $fail failed"
((fail == 0))
