// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test, type Page } from '@playwright/test';
import { OPERATOR_PASSWORD, signIn } from './session';
import { proveIfHeldBack, sendProving } from './step-up';
import { toast } from './toast';

// A number pasted from a web page brings a no-break space and a zero-width mark at its ends, which nobody sees: the
// field takes it as the number it is, and what is kept is the number alone.
test('a value pasted with invisible ends is taken and kept without them', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await signIn(page);
  const before = await readPhone(page);
  try {
    await page.goto('/platform');
    const card = page.getByTestId('platform-support');
    await card.getByTestId('field-phone').fill(' +216 71 000 001​ ');
    await card.getByTestId('field-phone').press('Tab');
    await expect(card.getByTestId('field-error-phone')).toHaveCount(0);
    const saved = page.waitForResponse(
      (response) =>
        new URL(response.url()).pathname === '/api/platform/settings' &&
        response.request().method() === 'PUT',
    );
    await page.getByTestId('platform-support-save').click();
    await proveIfHeldBack(page, OPERATOR_PASSWORD, saved);
    expect((await saved).ok()).toBe(true);
    await expect(toast(page)).toBeVisible();
    expect(await readPhone(page)).toBe('+216 71 000 001');
  } finally {
    const answer = await sendProving(
      page,
      OPERATOR_PASSWORD,
      'PUT',
      '/api/platform/settings/support.phone',
      { value: before },
    );
    if (answer.status < 200 || answer.status > 299)
      throw new Error(`restoring support.phone answered ${answer.status}`);
  }
});

async function readPhone(page: Page): Promise<string> {
  return page.evaluate(async () => {
    const answer = await fetch('/api/platform/settings');
    if (!answer.ok) throw new Error(`reading the platform settings answered ${answer.status}`);
    const rows = (await answer.json()) as { key: string; value: unknown }[];
    const value = rows.find((row) => row.key === 'support.phone')?.value;
    return typeof value === 'string' ? value : '';
  });
}
