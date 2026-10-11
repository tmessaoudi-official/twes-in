// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test, type Page } from '@playwright/test';
import { inACompany, signIn } from './session';

// A page's « Bientôt » actions are dashed in its bar where they fit and in « ⋮ » where they would not: shown, they
// must never add a row to the header. Each width is judged by the header itself, with and without them, so the test
// holds whichever side of the line a width falls on, and says which side it found.
const CSRF = '0123456789abcdef0123456789abcdef';

interface Rows {
  shown: boolean;
  /** The header's rows (title and bar, then each row of the bar) with the « Bientôt » buttons in the bar. */
  withThem: number;
  /** The same header's rows with them out of the flow. */
  without: number;
}

/** Counts the distinct tops of the title, the bar's row and every button in it, as drawn now and with them out. */
function rows(page: Page, barId: string): Promise<Rows> {
  return page.evaluate((barId) => {
    const header = document.querySelector<HTMLElement>('.twes-document-header')!;
    const bar = header.querySelector<HTMLElement>(`[data-testid="${barId}"]`)!;
    const planned = [...bar.querySelectorAll<HTMLElement>('[data-planned]')];
    // Centres, not tops: the header and the bar centre their items, which are not all one height.
    const count = () => {
      const centres = [header.firstElementChild!, ...bar.children]
        .filter((each) => {
          const box = each.getBoundingClientRect();
          return box.width > 0 && getComputedStyle(each).position !== 'absolute';
        })
        .map((each) => {
          const box = each.getBoundingClientRect();
          return box.top + box.height / 2;
        })
        .sort((a, b) => a - b);
      return centres.filter((centre, index) => index === 0 || centre - centres[index - 1]! > 8)
        .length;
    };
    const shown =
      planned.length > 0 &&
      planned.every((each) => !each.classList.contains('twes-planned-measure'));
    const withThem = count();
    planned.forEach((each) => each.classList.add('twes-planned-measure'));
    const without = count();
    if (shown) planned.forEach((each) => each.classList.remove('twes-planned-measure'));
    return { shown, withThem, without };
  }, barId);
}

interface Bar {
  page: string;
  bar: string;
  more: string;
  module: string;
  inMenu: string;
}

const BARS: readonly Bar[] = [
  // An invoice still due: its bar holds the most, the next step among them.
  {
    page: '/invoices/',
    bar: 'document-actions',
    more: 'document-more',
    module: 'mailing',
    inMenu: 'document-planned-mailing',
  },
  {
    page: '/vendors/',
    bar: 'record-bar',
    more: 'record-more',
    module: 'purchases',
    inMenu: 'record-planned-purchases',
  },
];

/**
 * An invoice of this run's own, issued so it is still due: CI seeds a company with no invoice at all, and one of
 * another spec's would tie this one to the order the shards run in.
 */
async function anIssuedInvoice(
  page: Page,
  number: string,
): Promise<{ customer: string; invoice: string }> {
  return page.evaluate(
    async ([csrf, customerNumber]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const send = async (path: string, body: unknown): Promise<{ id: string }> => {
        const response = await fetch(`${base}${path}`, {
          method: 'POST',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify(body),
        });
        if (!response.ok) throw new Error(`POST ${path} answered ${response.status}`);
        return (await response.json()) as { id: string };
      };
      const customer = await send('/customers', {
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
      });
      const options = (await (await fetch(`${base}/invoice-options`)).json()) as {
        units: { id: string; code: string }[];
      };
      const unit = options.units.find((each) => each.code === 'C62') ?? options.units[0];
      const invoice = await send('/invoices', {
        customerId: customer.id,
        establishmentId: null,
        lines: [
          {
            description: 'Réglage du tour',
            quantity: '2',
            unitId: unit?.id,
            unitPriceNet: '150',
            discountRate: null,
            taxComponentIds: [],
          },
        ],
      });
      await send(`/invoices/${invoice.id}/issue`, null);
      return { customer: customer.id, invoice: invoice.id };
    },
    [CSRF, number] as const,
  );
}

/** Retires the run's customer; its issued invoice stays in the shared company, as every issued one in these runs. */
async function retireCustomer(page: Page, id: string): Promise<void> {
  const status = await page.evaluate(
    async ([csrf, customerId]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const url = `/api/companies/${me.company.id}/customers/${customerId}`;
      // The write shape has no id: a body naming one is refused.
      const { id: _, ...fields } = (await (await fetch(url)).json()) as Record<string, unknown>;
      const retired = await fetch(url, {
        method: 'PUT',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({ ...fields, isActive: false }),
      });
      return retired.status;
    },
    [CSRF, id] as const,
  );
  expect(status).toBe(200);
}

/** A vendor of this run's own: the shared company's earlier runs retired theirs, and a retired one is not listed. */
async function newVendor(page: Page, number: string): Promise<void> {
  await page.goto('/vendors/new');
  await page.getByTestId('field-number').fill(number);
  await page.getByTestId('field-name').fill(`Bientôt ${number}`);
  await page.getByTestId('record-save').click();
  await expect(page).toHaveURL(/\/vendors\/[0-9a-f-]{36}$/, { timeout: 20_000 });
}

/** Retires the run's vendor, as the vendors scenario does with its own. */
async function retire(page: Page, number: string): Promise<void> {
  await page.evaluate(
    async ([csrf, vendorNumber]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const listed = (await (
        await fetch(`${base}/vendors?q=${encodeURIComponent(vendorNumber)}`)
      ).json()) as { member: { id: string; number: string }[] };
      const hit = listed.member.find((row) => row.number === vendorNumber);
      if (hit === undefined) return;
      const { id, ...fields } = (await (await fetch(`${base}/vendors/${hit.id}`)).json()) as {
        id: string;
      };
      const revised = await fetch(`${base}/vendors/${id}`, {
        method: 'PUT',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({ ...fields, isActive: false }),
      });
      if (!revised.ok) throw new Error(`retiring ${vendorNumber} answered ${revised.status}`);
    },
    [CSRF, number] as const,
  );
}

test('a page bar shows what is coming dashed only where the header keeps its rows', async ({
  page,
}, testInfo) => {
  await signIn(page);
  await inACompany(page, CSRF);

  const vendor = `E2E-SOON-${Date.now().toString(36).toUpperCase()}`;
  const found: Record<string, Rows> = {};
  let made: { customer: string; invoice: string } | null = null;
  try {
    for (const bar of BARS) {
      if (bar.bar === 'record-bar') await newVendor(page, vendor);
      else {
        made = await anIssuedInvoice(page, vendor);
        await page.goto(bar.page + made.invoice);
      }
      await expect(page.getByTestId(bar.bar)).toBeVisible();
      for (const width of [1440, 1280, 1024, 900]) {
        await page.setViewportSize({ width, height: 900 });
        // The bar measures again once the window has settled: two frames, then its answer.
        await page.evaluate(
          () => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done))),
        );
        const now = await rows(page, bar.bar);
        found[`${bar.bar} ${width}`] = now;
        await page.screenshot({ path: testInfo.outputPath(`${bar.bar}-${width}.png`) });
        if (now.shown) {
          expect(now.withThem, `${bar.bar} at ${width} px: shown, they added a row`).toBe(
            now.without,
          );
          await expect(page.getByTestId(`planned-action-${bar.module}`)).toBeVisible();
        } else {
          await page.getByTestId(bar.more).click();
          await expect(page.getByTestId(bar.inMenu)).toBeVisible();
          await page.keyboard.press('Escape');
        }
      }
    }
    testInfo.annotations.push({ type: 'rows', description: JSON.stringify(found) });
    // A vendor's bar is short: at 1440 px its one planned action has the room, so the shown side is exercised too.
    expect(found['record-bar 1440']!.shown).toBe(true);

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.getByTestId('planned-action-purchases').click();
    await expect(page).toHaveURL(/\/coming\/purchases$/);
  } finally {
    await retire(page, vendor);
    if (made !== null) await retireCustomer(page, made.customer);
  }
});
