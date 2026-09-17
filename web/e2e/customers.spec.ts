// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { signIn } from './session';
import { toast } from './toast';
import { wcagViolations } from './axe';

// G4 through the real stack: in the seeded Tunisian company, the owner creates a customer group, gives it its own
// payment terms, files a business customer in it and finds the terms inherited on the customer, then adds a contact.
// One database is shared by the whole suite, so the names are unique to the run and the customer is taken out of
// the group and deactivated (customers are never deleted) before the group is deleted, which forgets its terms.
const CSRF = '0123456789abcdef0123456789abcdef';
const TERMS = 'field-document__payment_terms_days';

/** Takes the run's customer out of its group, deactivates it, then deletes the group. */
async function retire(page: Page, number: string, groupName: string): Promise<void> {
  await page.evaluate(
    async ([csrf, customerNumber, group]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      // The list is paged: the customer is found by its number, then read whole as the single record it is.
      const customerNumbered = async (company: string, wanted: string) => {
        const listed = (await (
          await fetch(`${company}/customers?q=${encodeURIComponent(wanted)}`)
        ).json()) as { member: { id: string; number: string }[] };
        const hit = listed.member.find((row) => row.number === wanted);
        return hit === undefined
          ? undefined
          : ((await (await fetch(`${company}/customers/${hit.id}`)).json()) as {
              id: string;
              number: string;
            });
      };
      const base = `/api/companies/${me.company.id}`;
      const customer = await customerNumbered(base, customerNumber);
      if (customer) {
        // The write shape has no id: a body naming one is refused, and the group could then not be deleted.
        const { id, ...fields } = customer;
        const revised = await fetch(`${base}/customers/${id}`, {
          method: 'PUT',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify({ ...fields, customerGroupId: null, isActive: false }),
        });
        if (!revised.ok) throw new Error(`retiring ${customerNumber} answered ${revised.status}`);
      }
      const groups = (await (await fetch(`${base}/customer-groups`)).json()) as {
        id: string;
        name: string;
      }[];
      const found = groups.find((row) => row.name === group);
      if (found) {
        const deleted = await fetch(`${base}/customer-groups/${found.id}`, {
          method: 'DELETE',
          headers: { 'csrf-token': csrf },
        });
        if (!deleted.ok) throw new Error(`deleting ${group} answered ${deleted.status}`);
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
    await expect(toast(page)).toContainText('Les valeurs par défaut ont été enregistrées.');

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
    // Filtered: the shared company holds more customers than a page, and this one may sort onto the next.
    await page.getByTestId('list-filter').fill(number);
    await expect(page.getByTestId(`customer-${number}`)).toContainText(groupName);
    // The API searched it, and the address keeps the search: a reload opens the same list.
    await expect(page).toHaveURL(new RegExp(`[?&]q=${number}`));
    await page.reload();
    await expect(page.getByTestId('list-filter')).toHaveValue(number);
    await expect(page.getByTestId(`customer-${number}`)).toBeVisible();
  } finally {
    await retire(page, number, groupName);
  }
});
