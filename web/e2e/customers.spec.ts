// SPDX-License-Identifier: AGPL-3.0-or-later
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';

// G4 through the real stack: in the seeded Tunisian company, the owner creates a customer group, gives it its own
// payment terms, files a business customer in it and finds the terms inherited on the customer, then adds a contact.
// One database is shared by the whole suite, so the names are unique to the run and the customer is taken out of
// the group and deactivated (customers are never deleted) before the group is deleted, which forgets its terms.
const EMAIL = process.env['E2E_EMAIL'] ?? 'operator@twes.local';
const PASSWORD = process.env['E2E_PASSWORD'] ?? 'twes-operator-dev';
const CSRF = '0123456789abcdef0123456789abcdef';
const TERMS = 'field-document__payment_terms_days';

async function signIn(page: Page): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('email').fill(EMAIL);
  await page.getByTestId('password').fill(PASSWORD);
  await page.getByTestId('submit').click();
  await expect(page).toHaveURL(/\/$/);
}

async function wcagViolations(page: Page): Promise<string[]> {
  const axe = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze();
  return axe.violations.map((violation) => violation.id);
}

/** Takes the run's customer out of its group, deactivates it, then deletes the group. */
async function retire(page: Page, number: string, groupName: string): Promise<void> {
  await page.evaluate(
    async ([csrf, customerNumber, group]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const customers = (await (await fetch(`${base}/customers`)).json()) as {
        id: string;
        number: string;
      }[];
      const customer = customers.find((row) => row.number === customerNumber);
      if (customer) {
        await fetch(`${base}/customers/${customer.id}`, {
          method: 'PUT',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify({ ...customer, customerGroupId: null, isActive: false }),
        });
      }
      const groups = (await (await fetch(`${base}/customer-groups`)).json()) as {
        id: string;
        name: string;
      }[];
      const found = groups.find((row) => row.name === group);
      if (found) {
        await fetch(`${base}/customer-groups/${found.id}`, {
          method: 'DELETE',
          headers: { 'csrf-token': csrf },
        });
      }
    },
    [CSRF, number, groupName] as const,
  );
}

test("a customer in a group inherits the group's payment terms and gets a contact", async ({
  page,
}) => {
  const run = Date.now().toString(36).toUpperCase();
  const groupName = `E2E ${run}`;
  const number = `E2E-${run}`;
  await signIn(page);
  try {
    await page.goto('/customers/groups');
    await page.getByTestId('customer-group-add').click();
    await page.getByTestId('field-name').fill(groupName);
    await page.getByTestId('customer-group-save').click();
    await expect(page.getByTestId(`customer-group-${groupName}`)).toBeVisible();

    await page.getByTestId(`customer-group-edit-${groupName}`).click();
    await expect(page.getByTestId(TERMS)).toBeVisible();
    await page.getByTestId(TERMS).fill('45');
    await page.getByTestId('party-defaults-save').click();
    await expect(page.getByTestId('party-defaults-saved')).toBeVisible();

    await page.goto('/customers/new');
    await page.getByTestId('field-number').fill(number);
    await page.getByTestId('field-name').fill(`Carthage Conseil ${run}`);
    await page.getByTestId('field-identifier__matricule_fiscal').fill('1234567A/B/M/000');
    await page.getByTestId('field-customerGroupId').click();
    await page.getByRole('option', { name: groupName }).click();
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('customer-save').click();

    await expect(page).toHaveURL(/\/customers\/[0-9a-f-]{36}$/);
    await expect(page.getByTestId('customer-title')).toContainText(number);
    await expect(page.getByTestId(TERMS)).toHaveValue('45');

    await page.getByTestId('contact-add').click();
    await page.getByTestId('field-firstName').fill('Leila');
    await page.getByTestId('field-lastName').fill('Ben Salah');
    await page.getByTestId('contact-save').click();
    await expect(page.getByTestId('contacts-table')).toContainText('Leila Ben Salah');
    expect(await wcagViolations(page)).toEqual([]);

    await page.goto('/customers');
    await expect(page.getByTestId(`customer-${number}`)).toContainText(groupName);
  } finally {
    await retire(page, number, groupName);
  }
});
