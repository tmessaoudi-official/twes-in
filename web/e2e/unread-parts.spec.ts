// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { aCustomer, aProduct, forget, removed } from './catalogue';
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

test('the products list keeps its rows and names the custom fields it could not read under its title', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await refuse(page, /\/api\/companies\/[^/]+\/custom-fields\?/);

  await page.goto('/products');

  await expect(page.getByTestId('products-unread-custom_fields')).toHaveText(
    'Les champs personnalisés n’ont pas pu être lus (code : not_found). Rechargez la page ; si cela continue, signalez ce code.',
  );
  await expect(page.getByTestId('products-table')).toBeVisible();
  await expect(page.getByTestId('products-error')).toHaveCount(0);
});

test('the customers list keeps its rows and names the custom fields it could not read under its title', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await refuse(page, /\/api\/companies\/[^/]+\/custom-fields\?/);

  await page.goto('/customers');

  await expect(page.getByTestId('customers-unread-custom_fields')).toHaveText(
    'Les champs personnalisés n’ont pas pu être lus (code : not_found). Rechargez la page ; si cela continue, signalez ce code.',
  );
  await expect(page.getByTestId('customers-table')).toBeVisible();
  await expect(page.getByTestId('customers-error')).toHaveCount(0);
});

test('a customer shows with its custom fields said unread in their place', async ({ page }) => {
  const run = String(Date.now());
  await signIn(page);
  await inACompany(page, CSRF);
  const id = await aCustomer(page, `UNR-${run}`);
  try {
    await refuse(page, /\/api\/companies\/[^/]+\/custom-fields\?/);
    await page.goto(`/customers/${id}`);

    await expect(page.getByTestId('customer-title')).toContainText(`Client UNR-${run}`);
    await expect(page.getByTestId('section-unread-custom')).toHaveText(
      'Les champs personnalisés n’ont pas pu être lus (code : not_found). Rechargez la page ; si cela continue, signalez ce code.',
    );
  } finally {
    await page.unrouteAll({ behavior: 'ignoreErrors' });
    await removed(page, 'customer', id);
  }
});

test('the price lists stay listed and name the customer groups they could not read under their title', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await refuse(page, /\/api\/companies\/[^/]+\/customer-groups(\?|$)/);

  await page.goto('/price-lists');

  await expect(page.getByTestId('price-lists-unread-customer_groups')).toHaveText(
    'Les groupes de clients n’ont pas pu être lus (code : not_found). Rechargez la page ; si cela continue, signalez ce code.',
  );
  await expect(page.getByTestId('price-lists-error')).toHaveCount(0);
});

test("a product's homes stay listed and name the stock locations they could not read", async ({
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

    await expect(page.getByTestId('product-homes-unread-stock_locations')).toHaveText(
      'Les emplacements de stock n’ont pas pu être lus (code : not_found). Rechargez la page ; si cela continue, signalez ce code.',
    );
  } finally {
    await page.unrouteAll({ behavior: 'ignoreErrors' });
    await forget(page, [productId]);
  }
});
