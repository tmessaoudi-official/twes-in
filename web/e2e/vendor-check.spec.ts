// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test, type Page, type Response } from '@playwright/test';
import { runDigits } from './run';
import { inACompany, signIn } from './session';
import { toast } from './toast';

// docs/SPEC.md § 7, 2026-10-20 01:40: a vendor's name a file was unsure of is « Nom douteux » until a person confirms
// it, beside the mark a person puts with a note; both are named on its page, in the list filter and in « À surveiller ».
const CSRF = '0123456789abcdef0123456789abcdef';

test('a vendor read from a file with a doubtful name and a note is named to check until a person confirms it', async ({
  page,
}) => {
  test.setTimeout(60_000);
  // A leading digit sorts the vendor first in « À surveiller », which lists by number, ahead of the shared company's.
  const number = `0CHK-${runDigits()}`;
  await signIn(page);
  await inACompany(page, CSRF);
  try {
    await page.goto('/imports/vendors');
    await page.getByTestId('import-file').setInputFiles({
      name: 'fournisseurs.csv',
      mimeType: 'text/csv',
      buffer: Buffer.from(
        `number,name,to_check,name_doubtful\n${number},Sotumag ?,RIB à confirmer,oui\n`,
        'utf8',
      ),
    });
    await page.getByTestId('import-preview').click();
    await expect(page.getByTestId('import-created')).toContainText('1');
    await page.getByTestId('import-store').click();
    await expect(toast(page)).toContainText('1');

    const id = await vendorId(page, number);
    await page.goto(`/vendors/${id}`);
    await expect(page.getByTestId('vendor-check-marked')).toContainText('Marqué à la main');
    await expect(page.getByTestId('vendor-check-note')).toHaveText('RIB à confirmer');
    await expect(page.getByTestId('vendor-check-reasons')).toContainText('Nom douteux');

    // The list narrowed to what is to check finds it; narrowed to nothing to check, it does not.
    await page.goto(`/vendors?q=${number}&toCheck=yes`);
    await expect(page.getByTestId(`vendor-${number}`)).toBeVisible();
    await page.goto(`/vendors?q=${number}&toCheck=no`);
    await expect(page.getByTestId(`vendor-${number}`)).toHaveCount(0);

    await page.goto('/watch/vendor.to_check');
    const row = page.getByTestId('watch-table').getByRole('row').filter({ hasText: number });
    await expect(row).toContainText('Marqué à la main');
    await expect(row).toContainText('Nom douteux');

    // The name confirmed and the mark taken off: nothing is left to check, and the box asking about the name goes.
    await page.goto(`/vendors/${id}`);
    await page.getByTestId('field-nameDoubtful').locator('input').uncheck();
    await page.getByTestId('field-toCheck').locator('input').uncheck();
    await expect(page.getByTestId('field-checkNote')).toHaveCount(0);
    const saved = vendorSaved(page, id);
    await page.getByTestId('record-save').click();
    expect((await saved).ok()).toBe(true);
    await expect(toast(page)).toContainText('Le fournisseur a été enregistré.');
    await expect(page.getByTestId('vendor-check')).toHaveCount(0);
    await expect(page.getByTestId('field-nameDoubtful')).toHaveCount(0);
  } finally {
    await retire(page, number);
  }
});

async function vendorId(page: Page, number: string): Promise<string> {
  return page.evaluate(async (vendorNumber) => {
    const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
    const listed = await fetch(
      `/api/companies/${me.company.id}/vendors?q=${encodeURIComponent(vendorNumber)}`,
    );
    if (!listed.ok) throw new Error(`listing ${vendorNumber} answered ${listed.status}`);
    const vendor = (
      (await listed.json()) as { member: { id: string; number: string }[] }
    ).member.find((row) => row.number === vendorNumber);
    if (!vendor) throw new Error(`${vendorNumber} was not imported`);
    return vendor.id;
  }, number);
}

/** Deletes the run's vendor, if the import made it. */
async function retire(page: Page, number: string): Promise<void> {
  await page.evaluate(
    async ([csrf, vendorNumber]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const listed = await fetch(`${base}/vendors?q=${encodeURIComponent(vendorNumber)}`);
      if (!listed.ok) throw new Error(`listing ${vendorNumber} answered ${listed.status}`);
      const vendor = (
        (await listed.json()) as { member: { id: string; number: string }[] }
      ).member.find((row) => row.number === vendorNumber);
      if (vendor) {
        const deleted = await fetch(`${base}/record-removals`, {
          method: 'POST',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify({ subject: 'vendor', ids: [vendor.id] }),
        });
        if (!deleted.ok) throw new Error(`deleting ${vendorNumber} answered ${deleted.status}`);
      }
    },
    [CSRF, number] as const,
  );
}

/** The PUT of the vendor's own form, so a step reads the record only once that save has answered. */
function vendorSaved(page: Page, id: string): Promise<Response> {
  return page.waitForResponse(
    (response) =>
      response.request().method() === 'PUT' &&
      new URL(response.url()).pathname.endsWith(`/vendors/${id}`),
  );
}
