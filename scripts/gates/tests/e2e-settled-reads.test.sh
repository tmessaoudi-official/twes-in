#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
set -uo pipefail
TMPDIR=$(mktemp -d); export TMPDIR; trap 'rm -rf "$TMPDIR"' EXIT  # every case's fixture is made here and goes with it
GATE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/e2e-settled-reads.sh
pass=0; fail=0
check() { if [[ "$2" -eq "$3" && "$4" == *"$5"* ]]; then pass=$((pass+1)); echo "  ok   $1"; else fail=$((fail+1)); echo "  FAIL $1 (exit $2, wanted $3 with '$5')"; echo "$4" | sed 's/^/       /'; fi; }
# A tree shaped like the real one: a spec settling each thing it reads first, the ways the specs write it.
tree() {
  local d; d=$(mktemp -d); mkdir -p "$d/web/e2e"
  cat > "$d/web/e2e/codes.spec.ts" <<'TS'
import { expect, test } from '@playwright/test';

test('recovery codes are shown once', async ({ page }) => {
  const recovery = page.getByTestId('two-factor-recovery-codes').locator('li');
  await expect(recovery).toHaveCount(10);
  const codes = await recovery.allInnerTexts();
  await expect(page.getByTestId('invoice-title'), 'numbered').not.toBeEmpty();
  const title = (await page.getByTestId('invoice-title').textContent())?.trim() ?? '';
  await expect(page.locator('[data-testid^="stock-drawing-rect-"]')).toHaveCount(4);
  const drawn = await page
    .locator('[data-testid^="stock-drawing-rect-"]')
    .evaluateAll((nodes) => nodes.length);
  await expect.poll(async () => page.getByTestId('due').count()).toBe(1);
  const due = await page.getByTestId('due').innerText();
});
TS
  echo "$d"
}
spec() { printf "import { expect, test } from '@playwright/test';\n\ntest('a case', async ({ page }) => {\n%s\n});\n" "$2" > "$1"; }
run() { bash "$GATE" --root "$1" --floor 4 2>&1; }
echo "e2e-settled-reads gate"

d=$(tree); out=$(run "$d"); check "reads after toHaveCount, not.toBeEmpty with a message, a split chain and a poll pass" $? 0 "$out" "OK"

d=$(tree); spec "$d/web/e2e/tours.spec.ts" "  const lines = page.locator('[data-testid^=\"command-guide-\"]');
  await expect(lines.first()).toBeVisible();
  const guides = await lines.evaluateAll((options) => options.length);"
out=$(run "$d"); check "evaluateAll after a mere toBeVisible of its first is refused with its file and line" $? 1 "$out" "tours.spec.ts:6"

d=$(tree); spec "$d/web/e2e/pair.spec.ts" "  await page.getByTestId('phone-pair').click();
  const url = (await page.getByTestId('phone-pair-url').textContent())?.trim() ?? '';"
out=$(run "$d"); check "a textContent read of nothing settled is refused" $? 1 "$out" "pair.spec.ts:5"

d=$(tree); spec "$d/web/e2e/split.spec.ts" "  await expect(page.locator('li')).toHaveCount(2);
  const all = await page
    .locator('[data-testid^=\"photo-\"]')
    .evaluateAll((nodes) => nodes.length);"
out=$(run "$d"); check "a split chain settled on another locator is refused at the read's line" $? 1 "$out" "split.spec.ts:7"

d=$(tree); spec "$d/web/e2e/two.spec.ts" "  await expect(page.getByTestId('secret')).toHaveText(/\\w+/);
});

test('another case', async ({ page }) => {
  const secret = await page.getByTestId('secret').innerText();"
out=$(run "$d"); check "what another test settled does not count" $? 1 "$out" "two.spec.ts:8"

d=$(tree); spec "$d/web/e2e/later.spec.ts" "  const secret = await page.getByTestId('secret').innerText();
  await expect(page.getByTestId('secret')).toHaveText(/\\w+/);"
out=$(run "$d"); check "an assertion after the read does not settle it" $? 1 "$out" "later.spec.ts:4"

d=$(tree); spec "$d/web/e2e/said.spec.ts" "  // settled-read: the helper reads at its call, after the totals were asserted by the caller
  const figures = async (id: string) => (await page.getByTestId(id).textContent()) ?? '';"
out=$(run "$d"); check "a reason on the line above passes" $? 0 "$out" "OK"

d=$(tree); spec "$d/web/e2e/said.spec.ts" "  // settled-read: geometry of a row drawn above, read once the presses are done
  const boxes = await page
    .locator('td')
    .evaluateAll((cells) => cells.length);"
out=$(run "$d"); check "a reason above a statement whose chain spans lines passes" $? 0 "$out" "OK"

d=$(tree); spec "$d/web/e2e/said.spec.ts" "  const figures = async (id: string) => (await page.getByTestId(id).textContent()) ?? ''; // settled-read:"
out=$(run "$d"); check "a reason left empty is no reason" $? 1 "$out" "said.spec.ts:4"

d=$(tree); spec "$d/web/e2e/all.spec.ts" "  const texts = await page.getByTestId('x').allTextContents();"
out=$(run "$d"); check "allTextContents, which waits for nothing either, is read too" $? 1 "$out" "all.spec.ts:4"

d=$(tree); out=$(bash "$GATE" --root "$d" --floor 5 2>&1); check "fewer reads than the floor reds" $? 1 "$out" "floor"

d=$(tree); rm -r "$d/web/e2e"; out=$(run "$d"); check "a missing web/e2e is refused, not skipped" $? 1 "$out" "found no web/e2e"

echo; echo "$pass passed, $fail failed"; [[ $fail -eq 0 ]]
