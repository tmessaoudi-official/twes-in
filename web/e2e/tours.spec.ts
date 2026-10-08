// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { inACompany, signIn } from './session';

// Every guided tour runs on the real app from its first step to its last, opened from the palette the way a person
// opens it. A step pointing at what a screen no longer draws, or a page a tour can no longer reach, turns this red:
// the guide cannot quietly lie. The tours are found through the palette, so a new one is played without being named.
const CSRF = '0123456789abcdef0123456789abcdef';

test('every guide the palette offers runs from its first step to its last', async ({ page }) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1280, height: 800 });
  await signIn(page);
  await inACompany(page, CSRF);

  await page.keyboard.press('Control+k');
  await page.getByTestId('command-input').fill('guide');
  const lines = page.locator('[data-testid^="command-guide-"]');
  await expect(lines.first()).toBeVisible();
  const guides = await lines.evaluateAll((options) =>
    options.map((option) => option.getAttribute('data-testid') ?? ''),
  );
  await page.keyboard.press('Escape');

  for (const guide of guides) {
    await page.goto('/');
    // The shell listens for Ctrl K once it is drawn.
    await expect(page.getByTestId('greeting')).toBeVisible();
    await page.keyboard.press('Control+k');
    await page.getByTestId('command-input').fill('guide');
    await page.getByTestId(guide).click();

    for (let step = 1; ; step++) {
      expect(step, `${guide} ends`).toBeLessThan(30);
      await expect(page.getByTestId('tour-card'), `${guide}, step ${step}`).toBeVisible();
      // The card moves on once the step's anchor is found or given up on: wait for it before judging the step.
      await expect(page.getByTestId('tour-progress')).toHaveText(new RegExp(`Étape ${step} sur`));
      await expect(page.getByTestId('tour-missing'), `${guide}, step ${step}`).toHaveCount(0);
      await expect(page.locator('[data-tour-active]'), `${guide}, step ${step}`).toBeVisible();
      await expect(page.getByTestId('tour-title')).toBeFocused();
      const next = page.getByTestId('tour-next');
      const last = (await next.textContent())?.trim() === 'Terminer';
      await next.click();
      if (last) break;
    }
    await expect(page.getByTestId('tour-card')).toHaveCount(0);
    await expect(page.locator('[data-tour-active]')).toHaveCount(0);
  }
});

test('the help opens on « ? », says the words of the company’s country and starts a guide over the page', async ({
  page,
}) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await signIn(page);
  await inACompany(page, CSRF);
  await expect(page.getByTestId('greeting')).toBeVisible();

  await page.keyboard.press('?');
  await expect(page.getByTestId('help-title')).toBeVisible();
  await expect(page.getByTestId('glossary-invoice')).toBeVisible();
  await page.getByTestId('help-guide-first-invoice').click();

  await expect(page.getByTestId('help-title')).toHaveCount(0);
  await expect(page.getByTestId('tour-card')).toBeVisible();
  await expect(page.getByTestId('tour-progress')).toHaveText(/Étape 1 sur/);
  await page.keyboard.press('Escape');
  await expect(page.getByTestId('tour-card')).toHaveCount(0);

  await page.getByTestId('help-open').click();
  await page.getByTestId('help-shortcuts').click();
  await expect(page.getByTestId('help-title')).toHaveCount(0);
  await expect(page.getByRole('dialog')).toContainText('Raccourcis clavier');
});
