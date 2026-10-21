// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test, type Page, type Response } from '@playwright/test';
import { aCustomer, removed } from './catalogue';
import { runDigits } from './run';
import { inACompany, signIn } from './session';
import { toast } from './toast';

// docs/SPEC.md § 7, 2026-10-20 01:40: a customer marked « à vérifier » by hand with a note is named on its page, in the
// list filter and in « À surveiller », and the mark is taken off as it was put. A business lacking its number cannot be
// created here, and one held cannot be deleted once named, so the run marks an individual.
const CSRF = '0123456789abcdef0123456789abcdef';

test('a customer marked « À vérifier » with its note is named on its page, in the list filter and in « À surveiller »', async ({
  page,
}) => {
  test.setTimeout(60_000);
  // A leading digit sorts the customer first in « À surveiller », which lists by number, ahead of the shared company's.
  const number = `0CHK-${runDigits()}`;
  await signIn(page);
  await inACompany(page, CSRF);
  const id = await aCustomer(page, number);
  try {
    await page.goto(`/customers/${id}`);
    await expect(page.getByTestId('customer-title')).toContainText(number);
    // An individual lacks no number: nothing to check until it is marked.
    await expect(page.getByTestId('customer-check')).toHaveCount(0);

    await page.getByTestId('field-toCheck').locator('input').check();
    await page.getByTestId('field-checkNote').fill('Adresse à confirmer au prochain passage');
    const saved = customerSaved(page, id);
    await page.getByTestId('record-save').click();
    expect((await saved).ok()).toBe(true);
    await expect(toast(page)).toContainText('Le client a été enregistré.');
    await expect(page.getByTestId('customer-check-marked')).toContainText('Marqué à la main');
    await expect(page.getByTestId('customer-check-note')).toHaveText(
      'Adresse à confirmer au prochain passage',
    );

    // The list narrowed to what is to check finds it; narrowed to nothing to check, it does not.
    await page.goto(`/customers?q=${number}&toCheck=yes`);
    await expect(page.getByTestId(`customer-${number}`)).toBeVisible();
    await page.goto(`/customers?q=${number}&toCheck=no`);
    await expect(page.getByTestId(`customer-${number}`)).toHaveCount(0);

    // « À surveiller » lists it, its mark said, and opens it.
    await page.goto('/watch/customer.to_check');
    const row = page.getByTestId('watch-table').getByRole('row').filter({ hasText: number });
    await expect(row).toContainText('Marqué à la main');

    // Taken off, the mark and its note go together, and nothing is left to check.
    await page.goto(`/customers/${id}`);
    await page.getByTestId('field-toCheck').locator('input').uncheck();
    await expect(page.getByTestId('field-checkNote')).toHaveCount(0);
    const unmarked = customerSaved(page, id);
    await page.getByTestId('record-save').click();
    expect((await unmarked).ok()).toBe(true);
    await expect(page.getByTestId('customer-check')).toHaveCount(0);
    await page.goto(`/customers?q=${number}&toCheck=yes`);
    await expect(page.getByTestId(`customer-${number}`)).toHaveCount(0);
  } finally {
    await removed(page, 'customer', id);
  }
});

/** The PUT of the customer's own form, so a step reads the record only once that save has answered. */
function customerSaved(page: Page, id: string): Promise<Response> {
  return page.waitForResponse(
    (response) =>
      response.request().method() === 'PUT' &&
      new URL(response.url()).pathname.endsWith(`/customers/${id}`),
  );
}
