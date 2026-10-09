// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { inACompany, signIn } from './session';
import { toast } from './toast';
import { wcagViolations } from './axe';
import { aProduct } from './catalogue';

// A product's photos through the real stack (docs/SPEC.md § 7, 2026-10-08 23:02): two photos sent from this device,
// the first main, the second marked main instead and moved first with the buttons, one removed and put back from its
// toast, and the main photo shown beside the product's title and on its row of the list. Each run makes its own
// product, which keeps its photos: products are never deleted.
const CSRF = '0123456789abcdef0123456789abcdef';

/** A 2x1 PNG, red then blue: small, and a real picture the server can cut. */
const PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAIAAAB7QOjdAAAADUlEQVR4nGP4zwAE/wEHAAH/4iOeWQAAAABJRU5ErkJggg==',
  'base64',
);

test('a product shows its gallery, its main photo and its order, each changed without a pointer', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  const reference = `PH-${Date.now()}`;
  const id = await aProduct(page, reference);

  await page.goto(`/products/${id}`);
  await page.getByRole('tab', { name: 'Photos' }).click();
  await expect(page.getByTestId('product-photos-none')).toBeVisible();

  const add = page.getByTestId('product-photo-add');
  await add.setInputFiles({ name: 'face.png', mimeType: 'image/png', buffer: PNG });
  await expect(toast(page)).toContainText('Photo ajoutée.');
  await add.setInputFiles({ name: 'dos.png', mimeType: 'image/png', buffer: PNG });
  const photos = page.getByTestId('product-photos-list').locator('li');
  await expect(photos).toHaveCount(2);

  const [first, second] = await photos.evaluateAll((items) =>
    items.map((item) => item.getAttribute('data-testid')?.replace('product-photo-', '') ?? ''),
  );
  await expect(page.getByTestId(`product-photo-main-${first}`)).toBeVisible();
  await expect(page.getByTestId('product-main-photo').locator('img')).toHaveAttribute(
    'src',
    new RegExp(`/photos/${first}/content\\?size=small$`),
  );

  await page.getByTestId(`product-photo-make-main-${second}`).click();
  await expect(page.getByTestId(`product-photo-main-${second}`)).toBeVisible();
  await expect(page.getByTestId('product-main-photo').locator('img')).toHaveAttribute(
    'src',
    new RegExp(`/photos/${second}/content\\?size=small$`),
  );

  // By the keyboard: the earlier button of the second photo puts it first.
  await page.getByTestId(`product-photo-earlier-${second}`).focus();
  await page.keyboard.press('Enter');
  await expect(photos.first()).toHaveAttribute('data-testid', `product-photo-${second}`);

  await page.getByTestId(`product-photo-remove-${first}`).click();
  await expect(photos).toHaveCount(1);
  await expect(toast(page)).toContainText('Photo retirée.');
  await toast(page).getByRole('button', { name: 'Annuler' }).click();
  await expect(photos).toHaveCount(2);

  expect(await wcagViolations(page)).toEqual([]);

  await page.goto(`/products?q=${encodeURIComponent(reference)}`);
  await expect(
    page.getByTestId(`product-${reference}`).locator('[data-column="photo"] img'),
  ).toHaveAttribute('src', new RegExp(`/photos/${second}/content\\?size=small$`));
});
