// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Locator, type Page, test } from '@playwright/test';
import { sidewaysOverflow } from './overflow';
import { inACompany, signIn } from './session';

// A phone's list card leads with the name the record is known by, the code and its day under it, the amount at the
// end of that row and never cut; the whole card opens the record; the filters wait behind « Filtres (n) »; an active
// filter's chip has its own remove button a finger can hit (design proposal § 4, « Phone card » and « Toolbar »).

const CSRF = '0123456789abcdef0123456789abcdef';

async function onPhone(page: Page): Promise<void> {
  await page.setViewportSize({ width: 390, height: 844 });
  await signIn(page);
  await inACompany(page, CSRF);
}

/** The first card holding a title, once the list has settled on its rows. */
async function firstCard(page: Page): Promise<Locator> {
  const cards = page.getByTestId('list-cards').locator('li.twes-list-card-slotted');
  await expect(cards.first()).toBeVisible();
  return cards.first();
}

for (const path of ['/invoices', '/customers', '/products']) {
  test(
    `on a phone ${path} leads each card with a name, the code under it, the card a link`,
    { tag: '@phone' },
    async ({ page }) => {
      await onPhone(page);
      await page.goto(path);
      const card = await firstCard(page);

      const title = card.locator('[data-testid^="list-card-title-"]');
      const subtitle = card.locator('[data-testid^="list-card-subtitle-"]');
      await expect(title).not.toBeEmpty();
      await expect(subtitle.locator('bdi').first()).not.toBeEmpty();
      // The code the table links on is under the name, not the name itself.
      const code = (await subtitle.locator('bdi').first().innerText()).trim();
      expect((await title.innerText()).trim()).not.toBe(code);

      // A tap anywhere on the card's second row lands on the record's link.
      const box = (await subtitle.boundingBox())!;
      const lands = await page.evaluate(
        ({ x, y }) => document.elementFromPoint(x, y)?.closest('a.twes-list-card-link') !== null,
        { x: box.x + box.width / 2, y: box.y + box.height / 2 },
      );
      expect(lands).toBe(true);
      expect(await sidewaysOverflow(page), `${path} must not scroll sideways`).toBeLessThanOrEqual(
        0,
      );
    },
  );
}

test('on a phone an invoice card shows its amount whole', { tag: '@phone' }, async ({ page }) => {
  await onPhone(page);
  await page.goto('/invoices');
  await firstCard(page);
  const amount = page.locator('[data-testid^="list-card-amount-"]').first();
  await expect(amount).not.toBeEmpty();
  const cut = await amount.evaluate((node) => node.scrollWidth > node.clientWidth + 1);
  expect(cut).toBe(false);
});

test(
  'on a phone the filters wait behind « Filtres (n) », and a chip is taken off by its own button',
  { tag: '@phone' },
  async ({ page }) => {
    await onPhone(page);
    await page.goto('/invoices?status=draft');
    await firstCard(page);
    await expect(page.getByTestId('list-facet-status')).toHaveCount(0);
    const button = page.getByTestId('list-filters');
    await expect(button).toHaveAttribute('aria-expanded', 'false');
    await expect(page.getByTestId('list-filters-count')).toContainText('1');
    await button.click();
    await expect(button).toHaveAttribute('aria-expanded', 'true');
    await expect(
      page.getByTestId('list-filter-fold').getByTestId('list-facet-status'),
    ).toBeVisible();

    const remove = page.getByTestId('list-chips').locator('.twes-chip-remove').first();
    await expect(remove).toHaveAccessibleName(/Retirer le filtre/);
    const size = (await remove.boundingBox())!;
    expect(size.width).toBeGreaterThanOrEqual(24);
    expect(size.height).toBeGreaterThanOrEqual(24);
    await remove.click();
    await expect(page.getByTestId('list-chips')).toHaveCount(0);
  },
);
