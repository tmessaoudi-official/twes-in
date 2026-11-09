// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { aProduct, forget, removed, stockKept } from './catalogue';
import { inACompany, signIn } from './session';
import { wcagViolations } from './axe';

// A product selector that finds nothing says why, rather than « aucun produit » while the catalogue holds what was
// typed: the products switched off, with the way to them, and in the stock's selectors the goods whose stock is not
// kept (docs/SPEC.md § 7, S5.40). The run's products are deleted afterwards: nothing names them.

const CSRF = '0123456789abcdef0123456789abcdef';

test('a line finding only a switched-off product says so, and leads to it in the catalogue', async ({
  page,
}) => {
  // Digits only: the reference is the words typed, and its width must not vary per run.
  const reference = `E2E-OFF-${Date.now()}`;
  await signIn(page);
  await inACompany(page, CSRF);
  const id = await aProduct(page, reference);
  try {
    await forget(page, [id]);

    await page.goto('/invoices/new');
    await page.getByTestId('line-0-product').fill(reference);
    const none = page.getByTestId('line-0-product-none-found');
    await expect(none).toContainText('Aucun produit actif ne correspond.');
    await expect(none).toContainText('1 produit désactivé correspond');
    await expect(none).toContainText('réactivez-le depuis');
    expect(await wcagViolations(page)).toEqual([]);

    await page.getByTestId('line-0-product-unoffered-link').click();
    await expect(page).toHaveURL(new RegExp(`/products\\?.*q=${reference}`));
    await expect(page).toHaveURL(/status=inactive/);
    await expect(page.getByText(`Vis ${reference}`)).toBeVisible();
  } finally {
    await removed(page, 'product', id);
  }
});

test('the stock’s receiving says a product switched off, and goods whose stock is not kept', async ({
  page,
}) => {
  const stamp = Date.now();
  const off = `E2E-OFF-${stamp}`;
  const untracked = `E2E-NST-${stamp}`;
  await signIn(page);
  await inACompany(page, CSRF);
  const offId = await aProduct(page, off);
  const untrackedId = await aProduct(page, untracked);
  try {
    await forget(page, [offId]);
    await stockKept(page, untrackedId, false);

    await page.goto('/stock');
    await page.getByTestId('stock-receive').click();
    await page.getByTestId('field-productId').fill(off);
    const none = page.getByTestId('field-productId-none-found');
    await expect(none).toContainText('1 produit désactivé correspond');
    await expect(page.getByTestId('field-productId-unoffered-link')).toHaveText(
      'Voir les produits désactivés',
    );

    await page.getByTestId('field-productId').fill(untracked);
    await expect(none).toContainText('1 produit correspond mais son stock n’est pas suivi');
    await expect(page.getByTestId('field-productId-unoffered-link')).toHaveText(
      'Voir ces produits',
    );
    expect(await wcagViolations(page)).toEqual([]);
  } finally {
    await removed(page, 'product', offId);
    await removed(page, 'product', untrackedId);
  }
});
