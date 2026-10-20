// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { inACompany, OPERATOR_PASSWORD, signIn } from './session';
import { proveIfAsked } from './step-up';
import { toast } from './toast';
import { wcagViolations } from './axe';
import { runDigits } from './run';

// G8 through the real stack: in the seeded Tunisian company, the owner adds a vendor without any registration number,
// with its bank account and payment terms, revises it and finds it in the list. One database is shared by the whole
// suite, so the number is unique to the run and the vendor is deactivated at the end (vendors are never deleted).
const CSRF = '0123456789abcdef0123456789abcdef';

/** Deactivates the run's vendor. */
async function retire(page: Page, number: string): Promise<void> {
  await page.evaluate(
    async ([csrf, vendorNumber]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      // The list is paged: the vendor is found by its number, then read whole as the single record it is.
      const listed = (await (
        await fetch(`${base}/vendors?q=${encodeURIComponent(vendorNumber)}`)
      ).json()) as { member: { id: string; number: string }[] };
      const hit = listed.member.find((row) => row.number === vendorNumber);
      const vendor =
        hit === undefined
          ? undefined
          : ((await (await fetch(`${base}/vendors/${hit.id}`)).json()) as {
              id: string;
              number: string;
            });
      if (vendor) {
        // The write shape has no id: a body naming one is refused.
        const { id, ...fields } = vendor;
        const revised = await fetch(`${base}/vendors/${id}`, {
          method: 'PUT',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify({ ...fields, isActive: false }),
        });
        if (!revised.ok) throw new Error(`retiring ${vendorNumber} answered ${revised.status}`);
      }
    },
    [CSRF, number] as const,
  );
}

test('a vendor is added with its bank account and terms, then revised', async ({ page }) => {
  // Two saves behind a step-up, four accessibility scans and three filtered reads: on a loaded machine every step passed
  // and the clean-up met the default 30 s.
  test.setTimeout(60_000);
  const run = runDigits();
  const number = `E2E-${run}`;
  // Long enough that the title never shares a row with the bar: beside it, the « Bientôt » button would add one and
  // folds into « ⋮ », so a short name made the check below depend on how wide the run's letters happened to be.
  const name = `Sotumag, Société tunisienne de matériel ${run}`;
  await page.setViewportSize({ width: 1600, height: 900 });
  await signIn(page);
  await inACompany(page, CSRF);
  try {
    await page.goto('/vendors');
    await page.getByTestId('vendor-add').click();
    await expect(page).toHaveURL(/\/vendors\/new$/);
    await page.getByTestId('field-number').fill(number);
    await page.getByTestId('field-name').fill(name);
    await page.getByTestId('field-city').fill('Ben Arous');
    await page.getByTestId('field-iban').fill('tn59 1000 6035 1835 9847 8831');
    await page.getByTestId('field-paymentTermsDays').fill('30');
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('record-save').click();
    // Where a vendor is paid waits for a recent proof (docs/SPEC.md § 7); the shared session may hold one already.
    await proveIfAsked(page, OPERATOR_PASSWORD, toast(page));

    await expect(page).toHaveURL(/\/vendors\/[0-9a-f-]{36}$/);
    await expect(page.getByTestId('vendor-title')).toContainText(number);
    await expect(page.getByTestId('field-iban')).toHaveValue('TN5910006035183598478831');
    await expect(page.getByTestId('field-countryCode')).toHaveValue('TN');

    // Creating said "saved" too: let that toast go, or the next check reads it instead of the revision's.
    await expect(page.getByTestId('toast')).toHaveCount(0, { timeout: 10_000 });
    await page.getByTestId('field-email').fill('compta@sotumag.tn');
    await page.getByTestId('record-save').click();
    await expect(toast(page)).toContainText('Le fournisseur a été enregistré.');
    expect(await wcagViolations(page)).toEqual([]);

    // An order to this vendor starts from its page, the vendor already named in the order's header.
    const vendorAddress = page.url();
    const order = page.getByTestId('record-new-purchase');
    if (await order.isVisible()) await order.click();
    else {
      await page.getByTestId('record-more').click();
      await page.getByTestId('record-menu-new-purchase').click();
    }
    await expect(page).toHaveURL(/\/purchases\/new\?vendorId=[0-9a-f-]{36}$/);
    await expect(page.getByTestId('field-vendorId')).toHaveValue(new RegExp(name));
    expect(await wcagViolations(page)).toEqual([]);
    await page.goto(vendorAddress);

    await page.goto('/vendors');
    // Filtered: the shared company holds more vendors than a page, and the API searches the words.
    await page.getByTestId('list-filter').fill(number);
    await expect(page).toHaveURL(new RegExp(`[?&]q=${number}`));
    const row = page.getByTestId(`vendor-${number}`);
    await expect(row).toContainText(name);
    await expect(row).toContainText('30');
    expect(await wcagViolations(page)).toEqual([]);
    // The list's width stops growing with the window, and beside the boxes that select vendors a name this long leaves
    // the city, the column of lowest priority, no room. A phone's card hides no column.
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.getByTestId('list-cards').getByTestId(`vendor-${number}`)).toContainText(
      'Ben Arous',
    );
    await page.setViewportSize({ width: 1600, height: 900 });

    // Row 197: the terms as an interval of days, AND'd with the search, answered by the API.
    await page.goto(`/vendors?q=${number}&paymentTermsDays.min=30&paymentTermsDays.max=45`);
    await expect(row).toBeVisible();
    await page.goto(`/vendors?q=${number}&paymentTermsDays.min=31`);
    await expect(page.getByTestId('list-no-match')).toBeVisible();
  } finally {
    await retire(page, number);
  }
});
