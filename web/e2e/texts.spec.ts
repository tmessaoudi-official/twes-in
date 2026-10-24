// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { rowAction, rowMenuItem, rowMore } from './rows';
import { runDigits } from './run';
import { inACompany, OPERATOR_PASSWORD, signIn } from './session';
import { proveIfAsked } from './step-up';
import { toast } from './toast';

// « Textes »: the installation's wording of a screen text, then the company's own over it, which another open tab of
// the same person takes at once; taking the original back undoes each. Another company's open tabs take an installation
// wording on their next load only (docs/plans/text-overrides.plan.md, Known issues). The text worded is the texts
// page's own title, which no other scenario reads, and both wordings are taken back at the end. The mails and the
// printed documents taking the same wordings are certified by the API's tests (TextsInForceTest,
// TwigInvoiceTemplateTest), not here.
const CSRF = '0123456789abcdef0123456789abcdef';
const TITLE = 'text-screen:texts-texts.title';

/** Finds the text's row by its key, which the search reads whatever the text says now. */
async function found(page: Page): Promise<void> {
  await page.getByTestId('list-filter').fill('texts.title');
  await expect(page.getByTestId(TITLE)).toBeVisible();
}

async function word(page: Page, value: string): Promise<void> {
  await found(page);
  await rowAction(page, TITLE, 'edit').click();
  await expect(page.getByTestId('text-dialog-value')).toBeFocused();
  await page.getByTestId('text-dialog-value').fill(value);
  await page.getByTestId('text-dialog-save').click();
  await proveIfAsked(page, OPERATOR_PASSWORD, toast(page));
  await expect(toast(page)).toBeVisible();
  await expect(page.getByTestId('text-dialog-value')).toHaveCount(0);
}

async function takeOriginal(page: Page): Promise<void> {
  await found(page);
  await rowMore(page, TITLE).click();
  await rowMenuItem(page, 'reset').click();
  await proveIfAsked(page, OPERATOR_PASSWORD, toast(page));
  await expect(toast(page)).toBeVisible();
}

test('the installation words a screen text, a company its own over it, and the person’s other tab takes each at once', async ({
  page,
  context,
}) => {
  test.setTimeout(150_000);
  const run = runDigits();
  const installation = `Mots ${run} de l’installation`;
  const company = `Mots ${run} de la société`;
  await signIn(page);
  await inACompany(page, CSRF);
  const other = await context.newPage();
  let worded = { installation: false, company: false };
  try {
    await page.goto('/company/texts');
    await expect(page.getByTestId('texts-title')).toHaveText('Textes');
    await other.goto('/company/texts');
    await expect(other.getByTestId('texts-title')).toHaveText('Textes');

    // The installation's wording, for every company that has none of its own.
    await page.goto('/platform/texts');
    await word(page, installation);
    worded = { ...worded, installation: true };
    await expect(other.getByTestId('texts-title')).toHaveText(installation);

    // The company's own wins over it, in the tab that wrote it and in the other at once.
    await page.goto('/company/texts');
    await expect(page.getByTestId('texts-title')).toHaveText(installation);
    await word(page, company);
    worded = { ...worded, company: true };
    await expect(page.getByTestId('texts-title')).toHaveText(company);
    await expect(other.getByTestId('texts-title')).toHaveText(company);
    expect(await wcagViolations(page)).toEqual([]);
    await page.screenshot({ path: test.info().outputPath('texts-company.png'), fullPage: true });

    // Taking the original back undoes the company's, and the installation's shows again.
    await takeOriginal(page);
    worded = { ...worded, company: false };
    await expect(page.getByTestId('texts-title')).toHaveText(installation);
    await expect(other.getByTestId('texts-title')).toHaveText(installation);
  } finally {
    if (worded.company) {
      await page.goto('/company/texts');
      await takeOriginal(page);
    }
    if (worded.installation) {
      await page.goto('/platform/texts');
      await takeOriginal(page);
      await page.goto('/company/texts');
      await expect(page.getByTestId('texts-title')).toHaveText('Textes');
    }
    await other.close();
  }
});
