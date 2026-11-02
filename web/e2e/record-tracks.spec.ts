// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Locator, type Page, test } from '@playwright/test';
import { inACompany, leaveWhenAsked, signIn } from './session';
import { chooseLanguage } from './language';
import { choose } from './select';
import { sidewaysOverflow } from './overflow';

// A record page's two tracks (design proposal § 5, § 7.3 step 8): the page box decides, in rem, whether the summary
// stands at the inline end beside the main track, stuck under the header while it scrolls, or first in one column. A
// field reached by Tab comes to rest clear of the stuck header. Typed and never saved: the scenario leaves no document,
// only its customer, which it switches off.
const CSRF = '0123456789abcdef0123456789abcdef';

type Kind = { path: string; prefix: string; totals: string; form: string };
const INVOICE: Kind = {
  path: '/invoices/new',
  prefix: 'invoice',
  totals: 'invoice-totals',
  form: 'invoice-form',
};
const QUOTE: Kind = {
  path: '/quotes/new',
  prefix: 'quote',
  totals: 'quote-totals',
  form: 'quote-form',
};
const NOTE: Kind = {
  path: '/delivery-notes/new',
  prefix: 'delivery-note',
  totals: 'delivery-note-totals',
  form: 'delivery-note-form',
};

/** A customer for the run, through the API: what the page counts needs one, and the customers screens test themselves. */
async function aCustomer(page: Page, number: string, active: boolean): Promise<void> {
  const answered = await page.evaluate(
    async ([csrf, customerNumber, isActive]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}/customers`;
      const headers = { 'content-type': 'application/json', 'csrf-token': csrf };
      if (!isActive) {
        const listed = (await (
          await fetch(`${base}?q=${encodeURIComponent(customerNumber)}`)
        ).json()) as { member: { id: string; number: string }[] };
        const hit = listed.member.find((row) => row.number === customerNumber);
        if (hit === undefined) return 404;
        // The write shape has no id: a body naming one is refused.
        const { id: _, ...fields } = (await (await fetch(`${base}/${hit.id}`)).json()) as Record<
          string,
          unknown
        >;
        const retired = await fetch(`${base}/${hit.id}`, {
          method: 'PUT',
          headers,
          body: JSON.stringify({ ...fields, isActive: false }),
        });
        return retired.status;
      }
      const created = await fetch(base, {
        method: 'POST',
        headers,
        body: JSON.stringify({
          number: customerNumber,
          kind: 'individual',
          customerGroupId: null,
          taxRegime: 'standard',
          name: `Client ${customerNumber}`,
          legalName: null,
          identifiers: {},
          email: null,
          phone: null,
          website: null,
          billingAddressLine1: 'Rue de Rome',
          billingAddressLine2: null,
          billingPostalCode: '1000',
          billingCity: 'Tunis',
          billingCountryCode: 'TN',
          shippingAddressLine1: null,
          shippingAddressLine2: null,
          shippingPostalCode: null,
          shippingCity: null,
          shippingCountryCode: null,
          defaultTaxComponentIds: [],
          defaultDiscountRate: null,
          notes: null,
          isActive: true,
          customFields: {},
        }),
      });
      return created.status;
    },
    [CSRF, number, active] as const,
  );
  expect(answered).toBe(active ? 201 : 200);
}

/** A document typed for the customer, one line, until its totals show. */
async function typed(page: Page, kind: Kind, customer: string): Promise<void> {
  await page.goto(kind.path);
  await page.getByTestId(`${kind.prefix}-customer`).fill(customer);
  await page.getByRole('option', { name: new RegExp(`^${customer} · `) }).click();
  await page.getByTestId('line-0-description').fill('Conseil, deux jours');
  await page.getByTestId('line-0-quantity').fill('2');
  await page.getByTestId('line-0-price').fill('500');
  await choose(page, 'line-0-taxes', /19/);
  await expect(page.getByTestId(kind.totals)).toBeVisible();
}

async function box(locator: Locator) {
  const found = await locator.boundingBox();
  if (found === null) throw new Error('not laid out');
  return found;
}

/** Back to the page's top: typing a line scrolls it, and a stuck summary then stands lower than the main track. */
async function toTop(page: Page): Promise<void> {
  await page.evaluate(() => document.querySelector('.twes-shell-content')!.scrollTo(0, 0));
}

/** The summary track and the main one, as laid out. */
async function tracks(page: Page) {
  return {
    summary: await box(page.locator('.twes-record-summary')),
    main: await box(page.locator('.twes-record-main')),
  };
}

test.describe('a record page decides its tracks on its own box', () => {
  let customer = '';

  test.beforeEach(async ({ page }) => {
    customer = `E2E-TRK-${Date.now().toString(36).toUpperCase()}`;
    leaveWhenAsked(page);
    await signIn(page);
    await inACompany(page, CSRF);
    await aCustomer(page, customer, true);
  });

  test.afterEach(async ({ page }) => {
    await aCustomer(page, customer, false);
  });

  for (const kind of [INVOICE, NOTE]) {
    test(`${kind.prefix}: the summary stands at the inline end, stuck, and at the left in Arabic`, async ({
      page,
    }) => {
      await page.setViewportSize({ width: 1600, height: 900 });
      await typed(page, kind, customer);
      await toTop(page);

      const fr = await tracks(page);
      expect(fr.summary.x).toBeGreaterThan(fr.main.x + fr.main.width - 1);
      // Side by side: both start on the same line.
      expect(Math.abs(fr.summary.y - fr.main.y)).toBeLessThan(24);
      const summary = page.locator('.twes-record-summary');
      await expect(summary).toHaveCSS('position', 'sticky');
      await expect(summary).toHaveCSS('overflow-y', 'auto');
      // Never taller than what the stuck layers leave of the window, so its last figures stay in reach.
      const header = await box(page.locator('.twes-document-header'));
      expect(fr.summary.height).toBeLessThanOrEqual(900 - (header.y + header.height));

      await chooseLanguage(page, 'ar');
      try {
        await toTop(page);
        // Read in one go, again until it holds: the shell lays itself out anew in the other direction.
        await expect
          .poll(() =>
            page.evaluate(() => {
              const summary = document
                .querySelector('.twes-record-summary')!
                .getBoundingClientRect();
              const main = document.querySelector('.twes-record-main')!.getBoundingClientRect();
              return summary.right <= main.left + 1;
            }),
          )
          .toBe(true);
      } finally {
        await chooseLanguage(page, 'fr');
      }
    });

    test(`${kind.prefix}: on a phone, one column with the summary first`, async ({ page }) => {
      await page.setViewportSize({ width: 390, height: 844 });
      await typed(page, kind, customer);

      const { summary, main } = await tracks(page);
      expect(summary.y + summary.height).toBeLessThanOrEqual(main.y);
      expect(Math.abs(summary.width - main.width)).toBeLessThan(2);
      await expect(page.locator('.twes-record-summary')).toHaveCSS('position', 'static');
    });
  }

  // The page at 125 % in the narrowest window a phone gives (WCAG 1.4.10 reflow): every document, its summary first,
  // within the width. A saved order is read where the company has one; a new one shows its form.
  test(
    'at 125 % in a 320 px window, every document fits the width',
    { tag: '@phone' },
    async ({ page }) => {
      await page.addInitScript(() => localStorage.setItem('twes.text-size', '125'));
      await page.setViewportSize({ width: 320, height: 640 });
      try {
        for (const kind of [INVOICE, QUOTE, NOTE]) {
          await typed(page, kind, customer);
          await expect(page.locator('html')).toHaveClass(/\btwes-text-125\b/);
          expect(await sidewaysOverflow(page), kind.path).toBeLessThanOrEqual(0);
        }
        const order = await page.evaluate(async () => {
          const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
          const listed = await fetch(`/api/companies/${me.company.id}/purchase-orders`);
          if (!listed.ok) return null;
          const body = (await listed.json()) as {
            member: { id: string; totalNet: string | null }[];
          };
          return body.member.find((row) => row.totalNet !== null)?.id ?? null;
        });
        for (const path of ['/purchases/new', ...(order === null ? [] : [`/purchases/${order}`])]) {
          await page.goto(path);
          await expect(page.locator('h1').first(), path).toBeVisible();
          if (path !== '/purchases/new') {
            await expect(page.getByTestId('purchase-order-totals')).toBeVisible();
          }
          expect(await sidewaysOverflow(page), path).toBeLessThanOrEqual(0);
        }
      } finally {
        await page.evaluate(() => localStorage.removeItem('twes.text-size'));
      }
    },
  );

  // WCAG 2.4.11 (RGAA 10.7): a field brought into view by its focus is not left under the stuck header.
  test('a field reached by its focus comes to rest below the stuck header', async ({ page }) => {
    // A short window, so the page scrolls far enough to bring the field under the header.
    await page.setViewportSize({ width: 1280, height: 600 });
    await typed(page, INVOICE, customer);
    const scrollPadding = await page
      .locator('.twes-shell-content')
      .evaluate((scroller) => parseFloat(getComputedStyle(scroller).scrollPaddingTop));
    const header = page.locator('.twes-document-header');
    // As tall as the two layers stuck at the top: the shell's bar and the document's header below it.
    const bar = await box(page.locator('.twes-shell-content > header'));
    expect(Math.abs(scrollPadding - (bar.height + (await box(header)).height))).toBeLessThan(1);
    const field = page.getByTestId('line-0-description');
    // The field placed just under the shell's bar, where the header lies over it, as a scroll by hand leaves it.
    await page.evaluate(() => {
      const scroller = document.querySelector('.twes-shell-content')!;
      const bar = document.querySelector('.twes-shell-content > header')!.getBoundingClientRect();
      const target = document.querySelector('[data-testid="line-0-description"]')!;
      (document.activeElement as HTMLElement | null)?.blur();
      scroller.scrollTop += target.getBoundingClientRect().top - bar.bottom - 4;
    });
    expect((await box(field)).y).toBeLessThan((await box(header)).y + (await box(header)).height);

    await field.focus();
    await expect
      .poll(async () => (await box(field)).y - ((await box(header)).y + (await box(header)).height))
      .toBeGreaterThanOrEqual(0);
  });
});
