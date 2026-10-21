// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import type { Response } from '@playwright/test';
import { aProduct, forget, receivedAt, stockKept } from './catalogue';
import { inACompany, signIn } from './session';
import { toast } from './toast';
import { runDigits } from './run';

// docs/SPEC.md § 7, every outcome shown at once in every tab: the tab that acts hears its own change from its request's
// answer (`X-Live-Changes`), not from the connection, which says a change to every tab but the one that made it.
const CSRF = '0123456789abcdef0123456789abcdef';

/** The shell's read of « À surveiller », which it makes again when a watched kind changes. */
function isWatchSummary(response: Response): boolean {
  return (
    response.request().method() === 'GET' &&
    /^\/api\/companies\/[^/]+\/watch$/.test(new URL(response.url()).pathname)
  );
}

test('a change made in this tab reaches its other panels with no realtime connection at all', async ({
  page,
}) => {
  // A product, two receipts and the clean-up.
  test.setTimeout(60_000);
  // No connection reaches the server, so only the answer to this tab's own request can tell its other panels, and
  // another worker's changes in the shared company cannot make the shell read again in its place.
  await page.routeWebSocket(/\/connection\/websocket/, () => undefined);
  const run = runDigits();
  const reference = `OWN-${run}`;
  await signIn(page);
  await inACompany(page, CSRF);
  const ids: string[] = [];
  try {
    // Sold at 1, first bought at 0.5: the receipt on screen below moves its margin, which « À surveiller » counts.
    ids.push(await aProduct(page, reference));
    await stockKept(page, ids[0], true);
    await receivedAt(page, ids[0], '0.5');

    const first = page.waitForResponse(isWatchSummary);
    await page.goto('/stock');
    await first;

    await page.getByTestId('stock-receive').click();
    await page.getByTestId('field-productId').fill(reference);
    await page.getByRole('option', { name: `${reference} · Vis ${reference}` }).click();
    await page.getByTestId('field-quantity').fill('1');
    await page.getByTestId('field-unitCost').fill('0,6');
    await page.getByTestId('field-applyCost').click();
    await page.getByRole('option', { name: 'Prendre le coût de cette réception' }).click();
    const again = page.waitForResponse(isWatchSummary);
    await page.getByTestId('stock-movement-save').click();
    await expect(toast(page)).toContainText(`La marge de Vis ${reference}`);

    // The shell read « À surveiller » again on its own, and its menu says what it answered.
    const answered = (await (await again).json()) as { count: number };
    expect(answered.count).toBeGreaterThan(0);
    await expect(page.getByTestId('nav-count-watch').locator('[aria-label]')).toHaveAttribute(
      'aria-label',
      new RegExp(`^${answered.count}\\D`),
    );
  } finally {
    await forget(page, ids);
  }
});
