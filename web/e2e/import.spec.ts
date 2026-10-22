// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { aProduct, forget, stockKept } from './catalogue';
import { inACompany, OPERATOR_PASSWORD, signIn } from './session';
import { proveIfHeldBack } from './step-up';
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

async function choose(page: Page, name: string, contents: string): Promise<void> {
  await page
    .getByTestId('import-file')
    .setInputFiles({ name, mimeType: 'text/csv', buffer: Buffer.from(contents, 'utf8') });
}

test('a file is previewed before it is imported, and a refused row says why', async ({ page }) => {
  await signIn(page);
  await inACompany(page, CSRF);
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

// A shop's own file, written in its own words: each heading the subject does not know is paired once, the pairing is
// kept with the import, and the next file written the same way is read without asking. The headings carry the run's
// mark, so a pairing another run left in the shared company never answers for this one.
test('a file with its own headings is paired once, and the next one is read the same way', async ({
  page,
}) => {
  const reference = `Réf ${RUN}`;
  const name = `Raison sociale ${RUN}`;
  const remark = `Remarque ${RUN}`;
  const header = `${reference},${name},kind,${remark}`;
  const rows = page.getByTestId('import-pairing').locator('li');

  /** Opens a heading's Select and takes the column, as a person does; the panel hangs off the page. */
  async function pair(heading: string, column: string): Promise<void> {
    await rows.filter({ hasText: heading }).getByRole('combobox').click();
    await page.locator(`[role="option"][data-testid$="-option-${column}"]`).click();
  }

  await signIn(page);
  await inACompany(page, CSRF);
  await page.goto('/imports/customers');
  await expect(page.getByTestId('import-columns')).toBeVisible();

  await choose(
    page,
    'clients-maison.csv',
    `${header}\n${NUMBER}-P,Atelier du Port,individual,à rappeler\n`,
  );
  await page.getByTestId('import-preview').click();
  await expect(page.getByTestId('import-refusal')).toContainText(reference);
  // The run's own headings: a pairing an earlier run kept in the shared company is offered beside them.
  await expect(rows.filter({ hasText: RUN })).toHaveCount(3);

  await pair(reference, 'number');
  await pair(name, 'name');
  await pair(remark, 'ignored');
  await page.getByTestId('import-preview').click();
  await expect(page.getByTestId('import-created')).toContainText('1');
  await page.getByTestId('import-store').click();
  await expect(toast(page)).toContainText('1');

  // Back on the screen, the pairing is there before any file, said to come from the last import.
  await page.reload();
  await expect(page.getByTestId('import-pairing-from-last')).toBeVisible();
  await expect(rows).toHaveCount(3);
  await expect(rows.filter({ hasText: remark })).toContainText('Ne pas importer');

  // The next file written the same way is read as it is, with nothing asked. Previewed only.
  await choose(page, 'clients-maison.csv', `${header}\n${NUMBER}-Q,Atelier du Phare,individual,\n`);
  await page.getByTestId('import-preview').click();
  await expect(page.getByTestId('import-created')).toContainText('1');
  await expect(page.getByTestId('import-refusal')).toHaveCount(0);

  await retire(page, `${NUMBER}-P`);
});

// docs/SPEC.md § 7, 2026-10-09 10:40, ruling 5: an upsert's preview says what it changes in each record, old → new,
// and what it leaves alone; storing it asks first, naming how many records and which columns.
test('an upsert says what it changes before it changes anything', async ({ page }) => {
  const number = `UPS-${RUN}`;
  await signIn(page);
  await inACompany(page, CSRF);
  await page.goto('/imports/customers');
  await choose(page, 'clients.csv', `number,name,kind\n${number},Menuiserie Kairouan,individual\n`);
  await page.getByTestId('import-preview').click();
  await expect(page.getByTestId('import-store')).toBeEnabled();
  await page.getByTestId('import-store').click();
  await expect(toast(page)).toContainText('1 créés');

  try {
    await page.getByTestId('import-mode').getByText('mettre à jour').click();

    // The same file again changes nothing, and says so.
    await choose(
      page,
      'clients.csv',
      `number,name,kind\n${number},Menuiserie Kairouan,individual\n`,
    );
    await page.getByTestId('import-preview').click();
    await expect(page.getByTestId('import-unchanged')).toContainText('1');
    await expect(page.getByTestId('import-changes')).toHaveCount(0);

    await choose(
      page,
      'clients.csv',
      `number,name,kind\n${number},Menuiserie Kairouan et Fils,individual\n`,
    );
    await page.getByTestId('import-preview').click();
    await expect(page.getByTestId('import-changes')).toContainText('Menuiserie Kairouan');
    await expect(page.getByTestId('import-changes')).toContainText('Menuiserie Kairouan et Fils');
    await expect(page.getByTestId('import-changed-columns')).toContainText('name');

    // Asked first: « Ne rien importer » leaves the record as it was.
    await page.getByTestId('import-store').click();
    await expect(page.getByTestId('confirm-kind')).toHaveAttribute('data-kind', 'corrigeable');
    await expect(page.getByTestId('confirm-message')).toContainText('name (1)');
    await page.getByTestId('confirm-keep').click();
    // The dialog's closing animation keeps it in the page a moment: the next one would make two.
    await expect(page.getByTestId('confirm-keep')).toHaveCount(0);
    await expect(page.getByTestId('import-changes')).toBeVisible();

    await page.getByTestId('import-store').click();
    await page.getByTestId('confirm-run').click();
    // This import's own toast: the first import's may still be on screen, and leaving now would drop the run.
    await expect(toast(page)).toContainText('1 mis à jour');

    await page.goto('/customers');
    await page.getByTestId('list-filter').fill(number);
    await expect(page.getByTestId('customers-table')).toContainText('Menuiserie Kairouan et Fils');
  } finally {
    await retire(page, number);
  }
});

// docs/SPEC.md § 7, 2026-10-15 20:00, Round 1 Q12: a CSV is read in the encoding it was written in, which the report
// names, and the person may read it in another. Previewed only, so nothing is stored in the shared company. The page's
// words come in its own part: a heading left as its key would mean the part never opened.
test('a CSV from French Excel is read in its encoding, and may be read in another', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await page.goto('/imports/customers');
  await expect(page.getByTestId('import-columns')).toBeVisible();
  await expect(page.getByTestId('import-columns')).not.toContainText('import.customers.');
  await expect(page.getByTestId('import-encoding')).toHaveCount(0);

  // What French Excel writes: Windows-1252, where « é » is the one byte 0xE9.
  await page.getByTestId('import-file').setInputFiles({
    name: 'clients.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from(`number,name,kind\n${NUMBER}-E,Société du Lac,individual\n`, 'latin1'),
  });
  await expect(page.getByTestId('import-encoding')).toBeVisible();
  await page.getByTestId('import-preview').click();
  await expect(page.getByTestId('import-read-as')).toContainText('Windows-1252 (français)');
  await expect(page.getByTestId('import-created')).toContainText('1');

  // Chosen otherwise, the file is read again in that encoding, and the preview before no longer stands.
  await page.getByTestId('import-encoding-utf-8').locator('input').check();
  await expect(page.getByTestId('import-store')).toBeDisabled();
  await page.getByTestId('import-preview').click();
  await expect(page.getByTestId('import-read-as')).toContainText('UTF-8');
  await expect(page.getByTestId('import-rejections')).toContainText('caractères illisibles');
});

// docs/SPEC.md § 7, 2026-09-17 (3): a product file may leave the reference out; the preview says which one each new
// product would be given. Previewed only, so nothing is stored in the shared company.
test('a product file without references is previewed with the references it would give', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await page.goto('/imports/products');
  await expect(page.getByTestId('import-columns')).toBeVisible();

  await choose(page, 'produits.csv', `name,unit_code,unit_price_net\nCheville ${RUN},H87,0.1\n`);
  await page.getByTestId('import-preview').click();
  await expect(page.getByTestId('import-created')).toContainText('1');
  await expect(page.getByTestId('import-notes')).toContainText('Référence donnée');
  await expect(page.getByTestId('import-store')).toBeEnabled();
});

// Row 246: an opening-stock file counts or adds, and the preview says each row's stock before and after.
test('an opening-stock file is previewed with the stock each row leaves', async ({ page }) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await page.goto('/');
  const id = await aProduct(page, `OUV-${RUN}`);
  await stockKept(page, id, true);
  try {
    await page.goto('/imports/opening-stock');
    await expect(page.getByTestId('import-columns')).toContainText('stock_count');
    await expect(page.getByTestId('import-switch-recount')).toBeVisible();

    await choose(page, 'stock.csv', `reference,stock_count\nOUV-${RUN},12\n`);
    await page.getByTestId('import-preview').click();
    await expect(page.getByTestId('import-created')).toContainText('1');
    await expect(page.getByTestId('import-notes')).toContainText('0 → 12');
    await expect(page.getByTestId('import-already-imported')).toHaveCount(0);
    await expect(page.getByTestId('import-store')).toBeEnabled();
  } finally {
    await forget(page, [id]);
  }
});

// Row 246: a product file may bring quantities, and « Ignorer les quantités » reads it for its prices alone.
test('a product file adds stock, or is read for its prices alone', async ({ page }) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await page.goto('/');
  const id = await aProduct(page, `QTE-${RUN}`);
  await stockKept(page, id, true);
  try {
    await page.goto('/imports/products');
    await expect(page.getByTestId('import-columns')).toContainText('stock_add');
    await page.getByTestId('import-mode').getByText('mettre à jour').click();

    await choose(page, 'produits.csv', `reference,name,stock_add\nQTE-${RUN},Vis QTE,3\n`);
    await page.getByTestId('import-preview').click();
    await expect(page.getByTestId('import-updated')).toContainText('1');
    await expect(page.getByTestId('import-notes')).toContainText('0 → 3');

    // The checkbox's host spans the row: a click at its centre lands beside the label and ticks nothing.
    await page.getByTestId('import-switch-ignore_quantities').locator('input').check();
    await expect(page.getByTestId('import-store')).toBeDisabled();
    await page.getByTestId('import-preview').click();
    await expect(page.getByTestId('import-updated')).toContainText('1');
    await expect(page.getByTestId('import-notes')).toHaveCount(0);
  } finally {
    await forget(page, [id]);
  }
});

// « Annuler cet import »: the customers a file created go to « Récemment supprimés », the import reads as undone in
// « Imports récents », and putting the delete back stands the import again. The numbers are the run's own, and the
// customers are deactivated at the end, as the first scenario's.
test('an import is undone from its result, and put back from « Récemment supprimés »', async ({
  page,
}) => {
  const first = `UND-${RUN}-1`;
  const second = `UND-${RUN}-2`;
  await signIn(page);
  await inACompany(page, CSRF);
  await page.goto('/imports/customers');
  await choose(
    page,
    'clients.csv',
    `number,name,kind\n${first},Atelier du Port,individual\n${second},Bazar du Souk,individual\n`,
  );
  await page.getByTestId('import-preview').click();
  await expect(page.getByTestId('import-store')).toBeEnabled();
  await page.getByTestId('import-store').click();
  await expect(toast(page)).toContainText('2');

  // The import is listed, who ran it named, until when it may be undone.
  await expect(page.getByTestId('import-recent')).toContainText('clients');

  await page.getByTestId('import-undo').click();
  await expect(page.getByTestId('confirm-message')).toContainText('Récemment supprimés');
  const undone = page.waitForResponse(
    (response) => response.url().endsWith('/undo') && response.request().method() === 'POST',
  );
  await page.getByTestId('confirm-run').click();
  expect((await proveIfHeldBack(page, OPERATOR_PASSWORD, undone)).ok()).toBe(true);
  await expect(page.getByTestId('import-undone-removed')).toContainText('2');
  await expect(page.getByTestId('import-recent')).toContainText('Annulé le');

  await page.goto('/customers');
  await page.getByTestId('list-filter').fill(`UND-${RUN}`);
  await expect(page.getByTestId('customers-table')).not.toContainText('Atelier du Port');

  // The bin keeps the delete the undo made, and putting it back stands the import again.
  await page.goto('/company/recently-deleted');
  const removal = page.locator('li[data-testid^="removal-"]', { hasText: first });
  await expect(removal).toContainText('Retirés par l’annulation d’un import');
  await removal.getByRole('button', { name: 'Rétablir l’import' }).click();
  await expect(toast(page)).toContainText('L’import est rétabli');

  await page.goto('/customers');
  await page.getByTestId('list-filter').fill(`UND-${RUN}`);
  await expect(page.getByTestId('customers-table')).toContainText('Atelier du Port');
  await expect(page.getByTestId('customers-table')).toContainText('Bazar du Souk');

  await retire(page, first);
  await retire(page, second);
});

// docs/SPEC.md § 7, 2026-10-22 18:09: a mistyped e-mail no longer costs the customer. The preview says it is set aside,
// the value as the file wrote it and the refusal's own sentence, and the row may still be imported.
test('a doubtful cell is set aside, said on its cell, and the row kept', async ({ page }) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await page.goto('/imports/customers');
  await expect(page.getByTestId('import-columns')).toBeVisible();

  await choose(
    page,
    'clients.csv',
    `number,name,kind,email\nIMP-${RUN}-A,Courriel tordu,individual,compta(at)exemple.tn\n`,
  );
  await page.getByTestId('import-preview').click();

  await expect(page.getByTestId('import-created')).toContainText('1');
  await expect(page.getByTestId('import-rejections')).toHaveCount(0);
  await expect(page.getByTestId('import-notes')).toContainText(
    '«\u00a0compta(at)exemple.tn\u00a0» mis de côté',
  );
  await expect(page.getByTestId('import-notes')).toContainText('Vérifiez l’arobase et le point.');
  await expect(page.getByTestId('import-store')).toBeEnabled();
  await page.getByTestId('import-notes').screenshot({ path: 'test-results/import-set-aside.png' });
});
