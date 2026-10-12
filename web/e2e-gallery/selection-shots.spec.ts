// SPDX-License-Identifier: AGPL-3.0-or-later
import { mkdirSync } from 'node:fs';
import { type Page, test } from '@playwright/test';
import { forgetPresentationChoices } from '../e2e/presentation';
import { signInWithCode } from '../e2e/session';

// Row 260 slice 1b's before and after: the invoice list at 1440 and 390, and once built, with two rows selected.
// Not part of CI: make web-tools CMD="SHOTS=before npx playwright test -c playwright.gallery.config.ts selection-shots"
const PHASE = process.env['SHOTS'] ?? 'before';
const OUT = PHASE === '1c' ? '../var/claude/selection-1c' : `../var/claude/selection-1b/${PHASE}`;

async function settle(page: Page): Promise<void> {
  await page.waitForLoadState('networkidle', { timeout: 10_000 }).catch(() => undefined);
  await page.evaluate(() => document.fonts.ready);
  await page
    .getByTestId('activity-progress')
    .waitFor({ state: 'detached', timeout: 15_000 })
    .catch(() => undefined);
  await page.locator('a[data-testid^="list-link-"]').first().waitFor({ timeout: 20_000 });
  await page.waitForTimeout(500);
}

async function shot(page: Page, name: string): Promise<void> {
  await page.screenshot({ path: `${OUT}/${name}.png`, animations: 'disabled' });
}

async function workIn(page: Page, name: string): Promise<void> {
  const outcome = await page.evaluate(async (wanted) => {
    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'csrf-token': crypto.randomUUID(),
    };
    const listed = await fetch('/api/me/companies', { headers });
    const companies = (await listed.json()) as { companyId: string; name: string | null }[];
    const company = companies.find((each) => each.name === wanted);
    if (company === undefined) return `no company named ${wanted}`;
    const switched = await fetch('/api/me/company', {
      method: 'POST',
      headers,
      body: JSON.stringify({ companyId: company.companyId }),
    });
    return switched.ok ? 'ok' : `switching answered ${switched.status}`;
  }, name);
  if (outcome !== 'ok') throw new Error(outcome);
}

test('the invoice list, before and after selection', async ({ page }) => {
  mkdirSync(OUT, { recursive: true });
  await page.emulateMedia({ colorScheme: 'light' });
  await page.setViewportSize({ width: 1440, height: 900 });
  await signInWithCode(page);
  await workIn(page, 'Carthage Conseil');
  await forgetPresentationChoices(page);
  await page.goto('/invoices');
  await page
    .getByTestId('cookie-notice-close')
    .click()
    .catch(() => undefined);
  await settle(page);
  await shot(page, 'invoices-1440');
  if (PHASE === 'after') {
    await page.locator('[data-testid^="list-select-row-"]').nth(0).locator('input').check();
    await page.locator('[data-testid^="list-select-row-"]').nth(1).locator('input').check();
    await page.waitForTimeout(300);
    await shot(page, 'invoices-1440-selected');
  }
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/invoices');
  await settle(page);
  await shot(page, 'invoices-390');
  if (PHASE === 'after') {
    await page.getByTestId('list-select-mode').click();
    await page.locator('[data-testid^="list-select-row-"]:visible').nth(1).locator('input').check();
    await page.waitForTimeout(300);
    await shot(page, 'invoices-390-selected');
  }
});

// Slice 1c: the selection's « Exporter ▾ » and « PDF ▾ », and the dialog naming the drafts a PDF leaves out.
test('the bulk actions on an invoice selection, light and dark', async ({ page }) => {
  test.skip(PHASE !== '1c', 'only for the 1c shots');
  mkdirSync(OUT, { recursive: true });
  await page.setViewportSize({ width: 1440, height: 900 });
  await signInWithCode(page);
  await workIn(page, 'Carthage Conseil');
  await forgetPresentationChoices(page);
  for (const scheme of [
    process.env['SCHEME'] === 'dark' ? ('dark' as const) : ('light' as const),
  ]) {
    await page.emulateMedia({ colorScheme: scheme });
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/invoices?status=draft');
    await page
      .getByTestId('cookie-notice-close')
      .click()
      .catch(() => undefined);
    await settle(page);
    await page.locator('[data-testid^="list-select-row-"]').nth(0).locator('input').check();
    await page.locator('[data-testid^="list-select-row-"]').nth(1).locator('input').check();
    await page.waitForTimeout(300);
    await shot(page, `bar-1440-${scheme}`);
    await page.getByTestId('invoices-bulk-pdf').click();
    await page.waitForTimeout(400);
    await shot(page, `pdf-menu-1440-${scheme}`);
    await page.getByTestId('invoices-bulk-pdf-pdf').click();
    await page.getByTestId('invoice-bulk-skipped').waitFor();
    await page.waitForTimeout(500);
    await shot(page, `pdf-drafts-dialog-1440-${scheme}`);
    await page.getByTestId('invoice-bulk-include-drafts').locator('input').check();
    await page.waitForTimeout(300);
    await shot(page, `pdf-drafts-ticked-1440-${scheme}`);
    await page.getByTestId('invoice-bulk-cancel').click();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/invoices?status=draft');
    await settle(page);
    await page.getByTestId('list-select-mode').click();
    await page.locator('[data-testid^="list-select-row-"]:visible').nth(0).locator('input').check();
    await page.waitForTimeout(300);
    await shot(page, `bar-390-${scheme}`);
  }
});
