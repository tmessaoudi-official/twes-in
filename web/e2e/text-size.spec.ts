// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';

import { sidewaysOverflow } from './overflow';
import { forgetPresentationChoices } from './presentation';
import { inACompany, signIn } from './session';

// The text size belongs to the device (Mon compte › Préférences, kept as `twes.text-size` in this browser). At 125 %
// the root is 20 px, every rem follows, and the window class is decided on effective pixels: a 1,280 px window is
// the rail class, as its content has the room of a 1,024 px one. list-fit.spec.ts stays the 100 % test.
const CSRF = '0123456789abcdef0123456789abcdef';
const TEXT_SIZE = 'twes.text-size';

/** The device keeps 125 % before any page of the scenario opens, as a person who chose it before. */
async function at125(page: Page): Promise<void> {
  await page.addInitScript((key) => {
    localStorage.setItem(key, '125');
    // What the root carries the moment the application first draws, which the boot script decides.
    const watch = new MutationObserver(() => {
      const app = document.querySelector('app-root');
      if (app !== null && app.childElementCount > 0) {
        // The class, which the boot script guarantees: the rule giving it its size arrives with the stylesheet, which the
        // production build loads after its inlined critical styles, as every other rule does.
        (window as unknown as { firstDraw?: string }).firstDraw =
          document.documentElement.className;
        watch.disconnect();
      }
    });
    watch.observe(document, { childList: true, subtree: true });
  }, TEXT_SIZE);
}

const firstDraw = (page: Page) =>
  page.evaluate(() => (window as unknown as { firstDraw?: string }).firstDraw ?? null);
const rootSize = (page: Page) =>
  page.evaluate(() => getComputedStyle(document.documentElement).fontSize);

test.describe('at 125 % text', () => {
  test.beforeEach(async ({ page }) => {
    await at125(page);
  });

  test('a 1,280 px window draws the rail, at 20 px from the first paint, with nothing sideways', async ({
    page,
  }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await signIn(page);
    await expect.poll(() => rootSize(page)).toBe('20px');
    // The root carried the size when the application first drew, never set after it: the page would reflow on every load.
    await expect.poll(() => firstDraw(page)).toMatch(/\btwes-text-125\b/);
    await expect(page.getByTestId('shell-nav')).toHaveAttribute('data-window', 'medium');
    await expect(page.locator('html')).toHaveAttribute('data-window', 'medium');
    for (const path of ['/', '/invoices', '/customers', '/company/profile', '/account']) {
      await page.goto(path);
      await expect(page.locator('h1').first(), path).toBeVisible();
      expect(await sidewaysOverflow(page), path).toBeLessThanOrEqual(0);
    }
  });

  test(
    'a 320 px window is a phone, its bottom bar and its pages within the width',
    { tag: '@phone' },
    async ({ page }) => {
      await page.setViewportSize({ width: 320, height: 640 });
      await signIn(page);
      await expect(page.locator('html')).toHaveAttribute('data-window', 'compact');
      await expect(page.getByTestId('bottom-bar')).toBeVisible();
      for (const path of ['/', '/invoices', '/customers', '/account']) {
        await page.goto(path);
        await expect(page.locator('h1').first(), path).toBeVisible();
        expect(await sidewaysOverflow(page), path).toBeLessThanOrEqual(0);
      }
    },
  );

  test('a column widened at 125 % is kept at 100 % and drawn 1.25 times as wide', async ({
    page,
  }) => {
    await page.setViewportSize({ width: 1600, height: 900 });
    await signIn(page);
    await inACompany(page, CSRF);
    await forgetPresentationChoices(page);
    await page.goto('/customers');
    const handle = page.locator('[data-testid^="list-resize-"]').first();
    await expect(handle).toBeVisible();
    const before = Number(await handle.getAttribute('aria-valuenow'));
    const id = ((await handle.getAttribute('data-testid')) ?? '').replace('list-resize-', '');
    const saved = page.waitForResponse(
      (response) =>
        response.request().method() === 'PUT' &&
        new URL(response.url()).pathname.endsWith('/settings/presentation.list.customers'),
    );
    await handle.focus();
    await page.keyboard.press('ArrowRight');
    expect((await saved).ok()).toBe(true);
    // One keyboard step is 16 px at 100 %, whatever the text size: the width a person keeps.
    await expect(handle).toHaveAttribute('aria-valuenow', String(before + 16));
    const header = page.getByTestId(`list-header-${id}`);
    await expect
      .poll(async () => Math.round((await header.boundingBox())?.width ?? 0))
      .toBeGreaterThanOrEqual(Math.floor((before + 16) * 1.25));

    // The same device at 100 % reads the width back as it was kept.
    await page.evaluate((key) => localStorage.setItem(key, '100'), TEXT_SIZE);
    await page.addInitScript((key) => localStorage.setItem(key, '100'), TEXT_SIZE);
    await page.reload();
    await expect.poll(() => rootSize(page)).toBe('16px');
    await expect(page.getByTestId(`list-resize-${id}`)).toHaveAttribute(
      'aria-valuenow',
      String(before + 16),
    );
    await forgetPresentationChoices(page);
  });

  test('the window facing the customer keeps its own size', async ({ page }) => {
    await signIn(page);
    await page.goto('/customer-display');
    await expect.poll(() => rootSize(page)).toBe('16px');
    await expect(page.locator('html')).not.toHaveClass(/twes-text-/);
  });
});

test('« Taille du texte » on « Mon compte » sets the size of this device', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await signIn(page);
  await page.goto('/account?tab=preferences');
  const largest = page.getByTestId('account-text-size-125').locator('button');
  await expect(largest).toBeVisible();
  await largest.click();
  await expect.poll(() => rootSize(page)).toBe('20px');
  await expect.poll(() => page.evaluate((key) => localStorage.getItem(key), TEXT_SIZE)).toBe('125');
  await page.getByTestId('account-text-size-100').locator('button').click();
  await expect.poll(() => rootSize(page)).toBe('16px');
});
