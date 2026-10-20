// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Locator, type Page, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { runDigits } from './run';
import { inACompany, signIn } from './session';
import { toast } from './toast';

// A vendor nothing names is deleted without a question, taken back from the toast's « Annuler », deleted again and put
// back from « Récemment supprimés ». The run's vendor ends deleted: nothing names it, and its day then runs out.
const CSRF = '0123456789abcdef0123456789abcdef';

/** Whether an element's text and icon are drawn in the theme's error colour, read through a probe of that colour. */
async function colours(entry: Locator): Promise<{ label: string; icon: string }> {
  return entry.evaluate((element) => {
    const probe = document.createElement('span');
    probe.style.color = 'var(--mat-sys-error)';
    document.body.append(probe);
    const error = getComputedStyle(probe).color;
    probe.remove();
    const icon = element.querySelector('mat-icon');
    const named = (colour: string | undefined) => (colour === error ? 'error' : String(colour));
    return {
      label: named(getComputedStyle(element.querySelector('span') ?? element).color),
      icon: named(icon === null ? undefined : getComputedStyle(icon).color),
    };
  });
}

test('at phone width a toast that can be taken back keeps its sentence readable', async ({
  page,
}) => {
  await page.setViewportSize({ width: 400, height: 860 });
  const number = `E2E-DEL-${runDigits()}`;
  await signIn(page);
  await inACompany(page, CSRF);
  await page.goto('/vendors/new');
  await page.getByTestId('field-number').fill(number);
  await page.getByTestId('field-name').fill('Visserie du Cap Bon');
  await page.getByTestId('record-save').click();
  await expect(page).toHaveURL(/\/vendors\/[0-9a-f-]{36}$/);
  await expect(page.getByTestId('toast')).toHaveCount(0, { timeout: 10_000 });

  await page.getByTestId('record-more').click();
  await page.getByTestId('record-menu-delete').click();
  const shown = toast(page);
  await expect(shown).toContainText(number);
  // The sentence takes the toast's whole width, its kind and « Annuler » on a row of their own: squeezed beside them,
  // the vendor's name broke after every word.
  const [message, whole] = await Promise.all([
    shown.locator('.twes-toast-message').boundingBox(),
    shown.boundingBox(),
  ]);
  expect((message?.width ?? 0) / (whole?.width ?? 1)).toBeGreaterThan(0.7);
});

test('a vendor is deleted, taken back from its toast, and put back from « Récemment supprimés »', async ({
  page,
}) => {
  test.setTimeout(60_000);
  const number = `E2E-DEL-${runDigits()}`;
  await signIn(page);
  await inACompany(page, CSRF);

  await page.goto('/vendors/new');
  await page.getByTestId('field-number').fill(number);
  await page.getByTestId('field-name').fill('Visserie du Cap Bon');
  await page.getByTestId('record-save').click();
  await expect(page).toHaveURL(/\/vendors\/[0-9a-f-]{36}$/);
  const address = new URL(page.url()).pathname;
  // Creating said "saved": let it go, or the delete's toast is read beside it.
  await expect(page.getByTestId('toast')).toHaveCount(0, { timeout: 10_000 });

  async function deleteIt(): Promise<void> {
    await page.getByTestId('record-more').click();
    const entry = page.getByTestId('record-menu-delete');
    await expect(entry).toHaveAttribute('data-kind', 'annulable');
    // Drawn in the danger colour, which Material's menu item would otherwise paint over with its own.
    expect(await colours(entry)).toEqual({ label: 'error', icon: 'error' });
    await entry.click();
    // No question: the list, and the toast saying what went.
    await expect(page).toHaveURL(/\/vendors$/);
    await expect(toast(page)).toContainText(number);
  }

  await deleteIt();
  await toast(page).getByTestId('toast-action').click();
  await expect(toast(page)).toContainText('remis');
  await page.goto(address);
  await expect(page.getByTestId('vendor-title')).toContainText(number);
  await expect(page.getByTestId('toast')).toHaveCount(0, { timeout: 10_000 });

  await deleteIt();
  await page.goto('/company/recently-deleted');
  const removal = page.locator('li[data-testid^="removal-"]', { hasText: number });
  await expect(removal).toHaveCount(1);
  expect(await wcagViolations(page)).toEqual([]);
  await removal.getByRole('button').click();
  await expect(toast(page)).toContainText('remis');
  await expect(removal).toHaveCount(0);

  await page.goto(address);
  await expect(page.getByTestId('vendor-title')).toContainText(number);
  await expect(page.getByTestId('toast')).toHaveCount(0, { timeout: 10_000 });
  await deleteIt();
});

test('a product is deleted with its page, taken back from its toast, and put back from « Récemment supprimés »', async ({
  page,
}) => {
  test.setTimeout(60_000);
  const reference = `E2E-DEL-${runDigits()}`;
  await signIn(page);
  await inACompany(page, CSRF);

  await page.goto('/products/new');
  await page.getByTestId('field-reference').fill(reference);
  await page.getByTestId('field-name').fill('Cheville 8 mm');
  await page.getByTestId('field-unitPriceNet').fill('0,2');
  await page.getByTestId('record-save').click();
  await expect(page).toHaveURL(/\/products\/[0-9a-f-]{36}/);
  const address = new URL(page.url()).pathname;
  await expect(page.getByTestId('toast')).toHaveCount(0, { timeout: 10_000 });

  async function deleteIt(): Promise<void> {
    await page.getByTestId('record-more').click();
    const entry = page.getByTestId('record-menu-delete');
    await expect(entry).toHaveAttribute('data-kind', 'annulable');
    await entry.click();
    await expect(page).toHaveURL(/\/products$/);
    await expect(toast(page)).toContainText(reference);
  }

  await deleteIt();
  await toast(page).getByTestId('toast-action').click();
  await expect(toast(page)).toContainText('remis');
  await page.goto(address);
  await expect(page.getByTestId('field-reference')).toHaveValue(reference);
  await expect(page.getByTestId('toast')).toHaveCount(0, { timeout: 10_000 });

  await deleteIt();
  await page.goto('/company/recently-deleted');
  const removal = page.locator('li[data-testid^="removal-"]', { hasText: reference });
  await expect(removal).toContainText('1 produit');
  await removal.getByRole('button').click();
  await expect(toast(page)).toContainText('remis');
  await expect(removal).toHaveCount(0);

  await page.goto(address);
  await expect(page.getByTestId('field-reference')).toHaveValue(reference);
  await expect(page.getByTestId('toast')).toHaveCount(0, { timeout: 10_000 });
  await deleteIt();
});

/** Makes a category, and one under it, through the API: what is shown here is the delete, not the parent picker. */
async function categories(
  page: Page,
  kind: 'product-categories' | 'expense-categories',
  name: string,
  child: string | null,
): Promise<void> {
  await page.evaluate(
    async ([csrf, kind, name, child]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const made = async (body: object): Promise<string> => {
        const response = await fetch(`/api/companies/${me.company.id}/${kind}`, {
          method: 'POST',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify({ ...body, isActive: true }),
        });
        if (!response.ok)
          throw new Error(`creating ${JSON.stringify(body)} answered ${response.status}`);
        return ((await response.json()) as { id: string }).id;
      };
      const top = await made({ name, parentId: null });
      if (child !== null) await made({ name: child, parentId: top });
    },
    [CSRF, kind, name, child] as const,
  );
}

test('a category nothing names is deleted from its list and taken back; one a subcategory names is refused', async ({
  page,
}) => {
  test.setTimeout(60_000);
  const parent = `E2E-DEL ${runDigits()}`;
  const child = `${parent} B`;
  await signIn(page);
  await inACompany(page, CSRF);
  await categories(page, 'expense-categories', parent, child);

  await page.goto('/expenses/categories');
  await page.getByTestId('list-filter').fill(parent);
  async function deleteRow(name: string): Promise<void> {
    await page
      .getByTestId(`expense-category-${name}`)
      .locator('[data-testid^="row-more-"]')
      .click();
    const entry = page.locator('[data-testid^="row-menu-delete-"]');
    await expect(entry).toHaveAttribute('data-kind', 'annulable');
    await entry.click();
  }

  // Its subcategory still names it: nothing goes, and the toast says what names it.
  await deleteRow(parent);
  await expect(toast(page)).toContainText('1 sous-catégorie');
  await expect(page.getByTestId(`expense-category-${parent}`)).toBeVisible();

  await deleteRow(child);
  await expect(toast(page)).toContainText(child);
  await expect(page.getByTestId(`expense-category-${child}`)).toHaveCount(0);
  await toast(page).getByTestId('toast-action').click();
  await expect(toast(page)).toContainText('remis');
  await expect(page.getByTestId(`expense-category-${child}`)).toBeVisible();

  // Both go, the subcategory first; the run leaves nothing behind once their day runs out.
  await deleteRow(child);
  await expect(page.getByTestId(`expense-category-${child}`)).toHaveCount(0);
  await deleteRow(parent);
  await expect(toast(page)).toContainText(parent);
  await expect(page.getByTestId(`expense-category-${parent}`)).toHaveCount(0);

  await page.goto('/company/recently-deleted');
  await expect(
    page.locator('li[data-testid^="removal-"]', { hasText: parent }).last(),
  ).toContainText('1 catégorie de dépenses');
});

test('an empty product category is deleted from its list without a question', async ({ page }) => {
  const name = `E2E-DEL ${runDigits()}`;
  await signIn(page);
  await inACompany(page, CSRF);
  await categories(page, 'product-categories', name, null);

  await page.goto('/products/categories');
  await page.getByTestId('list-filter').fill(name);
  await page.getByTestId(`product-category-${name}`).locator('[data-testid^="row-more-"]').click();
  const entry = page.locator('[data-testid^="row-menu-delete-"]');
  await expect(entry).toHaveAttribute('data-kind', 'annulable');
  await entry.click();

  await expect(toast(page)).toContainText(name);
  await expect(page.getByTestId(`product-category-${name}`)).toHaveCount(0);
});
