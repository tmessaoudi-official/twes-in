// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { signIn } from './session';
import { toast } from './toast';

// Row 59 through the real stack: the owner opens the import screen for customers, takes the empty file, previews a
// file the rules refuse and reads why, then previews and imports a clean one. One database is shared by the whole
// suite, so the numbers are unique to the run and the customer it creates is deactivated at the end.
const CSRF = '0123456789abcdef0123456789abcdef';
const RUN = Date.now().toString(36).toUpperCase();
const NUMBER = `IMP-${RUN}`;

/** Deactivates the customer the run imported, so the shared company does not grow a row per run. */
async function retire(page: Page, number: string): Promise<void> {
  await page.evaluate(
    async ([csrf, customerNumber]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const listed = (await (
        await fetch(`${base}/customers?q=${encodeURIComponent(customerNumber)}`)
      ).json()) as { member: { id: string; number: string }[] };
      const hit = listed.member.find((row) => row.number === customerNumber);
      if (!hit) return;
      const customer = (await (await fetch(`${base}/customers/${hit.id}`)).json()) as {
        id: string;
      } & Record<string, unknown>;
      // The write shape has no id: a body naming one is refused.
      const { id, ...fields } = customer;
      const revised = await fetch(`${base}/customers/${id}`, {
        method: 'PUT',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({ ...fields, isActive: false }),
      });
      if (!revised.ok) throw new Error(`retiring ${customerNumber} answered ${revised.status}`);
    },
    [CSRF, number] as const,
  );
}

/**
 * Makes sure the session is working in a company. A sign-in picks one only for a single membership
 * (ChooseWorkingCompany), so a seed where the operator belongs to several leaves none chosen and every company screen
 * reads "no company" — which is a state of the database, not of the screen under test.
 */
async function inACompany(page: Page): Promise<void> {
  await page.evaluate(async (csrf) => {
    const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } | null };
    if (me.company !== null) return;
    const answered = (await (await fetch('/api/me/companies')).json()) as
      { member: { companyId: string; name: string }[] } | { companyId: string; name: string }[];
    const mine = Array.isArray(answered) ? answered : answered.member;
    const demo = mine.find((row) => row.name === 'Demo') ?? mine[0];
    if (demo === undefined) throw new Error('the operator belongs to no company at all');
    const moved = await fetch('/api/me/company', {
      method: 'POST',
      headers: { 'content-type': 'application/json', 'csrf-token': csrf },
      body: JSON.stringify({ companyId: demo.companyId }),
    });
    if (!moved.ok)
      throw new Error(`choosing ${demo.name} answered ${moved.status}: ${await moved.text()}`);
  }, CSRF);
}

async function choose(page: Page, name: string, contents: string): Promise<void> {
  await page
    .getByTestId('import-file')
    .setInputFiles({ name, mimeType: 'text/csv', buffer: Buffer.from(contents, 'utf8') });
}

test('a file is previewed before it is imported, and a refused row says why', async ({ page }) => {
  await signIn(page);
  await inACompany(page);
  await page.goto('/imports/customers');

  // The screen is driven by the guide the API answers for this company.
  await expect(page.getByTestId('import-columns')).toBeVisible();
  await expect(page.getByTestId('import-identity')).toContainText('number');
  await expect(page.getByTestId('import-store')).toBeDisabled();

  // The empty file to fill in comes from the API, under the subject's own name.
  const [downloaded] = await Promise.all([
    page.waitForEvent('download'),
    page.getByTestId('import-template-csv').click(),
  ]);
  expect(downloaded.suggestedFilename()).toBe('customers.csv');

  // A file naming the same customer twice: the second line is one thing twice, and nothing is stored.
  await choose(
    page,
    'clients.csv',
    `number,name,kind\n${NUMBER},Quincaillerie du Lac,individual\n${NUMBER},Quincaillerie du Lac,individual\n`,
  );
  await page.getByTestId('import-preview').click();
  await expect(page.getByTestId('import-rejections')).toContainText('ligne 2');
  await expect(page.getByTestId('import-nothing-stored')).toBeVisible();
  await expect(page.getByTestId('import-store')).toBeDisabled();

  // The same file without the repeat: the preview is clean, and only then may it be imported.
  await choose(
    page,
    'clients.csv',
    `number,name,kind\n${NUMBER},Quincaillerie du Lac,individual\n`,
  );
  await page.getByTestId('import-preview').click();
  await expect(page.getByTestId('import-created')).toContainText('1');
  await expect(page.getByTestId('import-rejections')).toHaveCount(0);
  await expect(page.getByTestId('import-store')).toBeEnabled();

  await page.getByTestId('import-store').click();
  await expect(toast(page)).toContainText('1');

  // What the file asked for is a customer like any other.
  await page.goto('/customers');
  await page.getByTestId('list-filter').fill(NUMBER);
  await expect(page.getByTestId('customers-table')).toContainText('Quincaillerie du Lac');

  await retire(page, NUMBER);
});
