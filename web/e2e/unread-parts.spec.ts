// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { aProduct, forget } from './catalogue';
import { inACompany, signIn } from './session';

// A part another feature reads for a screen, refused, is said as that part not read with the code the API answered,
// never as a lost connection nor in the screen's own words about its record. The API is made to refuse the part here:
// nothing in a shared company refuses it on its own.
const CSRF = '0123456789abcdef0123456789abcdef';

/** The API answering 404 to every read of the address the pattern names, as a part refused by its feature would. */
async function refuse(page: Page, address: RegExp): Promise<void> {
  await page.route(address, (route) =>
    route.request().method() === 'GET'
      ? route.fulfill({ status: 404, contentType: 'application/json', body: '{}' })
      : route.fallback(),
  );
}

test('the products list names the custom fields it could not read, with their code', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await refuse(page, /\/api\/companies\/[^/]+\/custom-fields\?/);

  await page.goto('/products');

  await expect(page.getByTestId('products-error')).toHaveText(
    'Les champs personnalisés n’ont pas pu être lus (code : not_found). Rechargez la page ; si cela continue, signalez ce code.',
  );
});

test('the customers list names the custom fields it could not read, with their code', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await refuse(page, /\/api\/companies\/[^/]+\/custom-fields\?/);

  await page.goto('/customers');

  await expect(page.getByTestId('customers-error')).toHaveText(
    'Les champs personnalisés n’ont pas pu être lus (code : not_found). Rechargez la page ; si cela continue, signalez ce code.',
  );
});

test('the price lists name the customer groups they could not read, with their code', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await refuse(page, /\/api\/companies\/[^/]+\/customer-groups(\?|$)/);

  await page.goto('/price-lists');

  await expect(page.getByTestId('price-lists-error')).toHaveText(
    'Les groupes de clients n’ont pas pu être lus (code : not_found). Rechargez la page ; si cela continue, signalez ce code.',
  );
});

test("a product's homes name the stock locations they could not read, with their code", async ({
  page,
}) => {
  const run = Date.now().toString(36).toUpperCase();
  await signIn(page);
  await inACompany(page, CSRF);
  const productId = await aProduct(page, `UNR-${run}`);
  try {
    await refuse(page, /\/api\/companies\/[^/]+\/stock-locations(\?|$)/);
    await page.goto(`/products/${productId}`);
    await page.getByRole('tab', { name: 'Où il est rangé' }).click();

    await expect(page.getByTestId('product-homes-error')).toContainText(
      'Les emplacements de stock n’ont pas pu être lus (code : not_found). Rechargez la page ; si cela continue, signalez ce code.',
    );
  } finally {
    await page.unrouteAll({ behavior: 'ignoreErrors' });
    await forget(page, [productId]);
  }
});
