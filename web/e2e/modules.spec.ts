// SPDX-License-Identifier: AGPL-3.0-or-later
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';
import { signIn } from './session';

// G5 module registry through the real stack: in the seeded company, the owner switches the customers module off,
// its entries leave the navigation, its page sends them home and its API answers 404; switching it back on brings
// all of it back. Delivery notes need customers, so they are switched off first and back on last. The suite shares
// one database and runs serially, and both modules are switched on again whatever happens, so no other scenario
// ever finds either off.
const CSRF = '0123456789abcdef0123456789abcdef';

async function wcagViolations(page: Page): Promise<string[]> {
  const axe = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze();
  return axe.violations.map((violation) => violation.id);
}

/** What the API answers for the working company's customers. */
async function customersStatus(page: Page): Promise<number> {
  return page.evaluate(async () => {
    const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
    return (await fetch(`/api/companies/${me.company.id}/customers`)).status;
  });
}

async function switchModule(page: Page, key: string, enabled: boolean): Promise<void> {
  await page.evaluate(
    async ([csrf, moduleKey, on]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const switched = await fetch(`/api/companies/${me.company.id}/modules/${moduleKey}`, {
        method: 'PUT',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({ enabled: on }),
      });
      // Left off, every later scenario of that module would fail for a reason that is not its own.
      if (!switched.ok)
        throw new Error(`switching ${moduleKey} to ${on} answered ${switched.status}`);
    },
    [CSRF, key, enabled] as const,
  );
}

test('a module switched off leaves the navigation, its pages and its API until it is switched on again', async ({
  page,
}) => {
  await signIn(page);
  try {
    await switchModule(page, 'invoices', false);
    await switchModule(page, 'delivery_notes', false);
    await page.goto('/company/modules');
    await expect(page.getByTestId('nav-customers')).toBeVisible();
    expect(await wcagViolations(page)).toEqual([]);

    await page.getByTestId('module-toggle-customers').getByRole('switch').click();
    await expect(page.getByTestId('nav-customers')).toHaveCount(0);
    expect(await customersStatus(page)).toBe(404);
    await page.goto('/customers');
    await expect(page).toHaveURL(/\/$/);

    await page.goto('/company/modules');
    await page.getByTestId('module-toggle-customers').getByRole('switch').click();
    await expect(page.getByTestId('nav-customers')).toBeVisible();
    expect(await customersStatus(page)).toBe(200);
  } finally {
    await switchModule(page, 'customers', true);
    await switchModule(page, 'delivery_notes', true);
  }
});
