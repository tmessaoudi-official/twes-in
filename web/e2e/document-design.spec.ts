// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { inACompany, signIn } from './session';
import { toast } from './toast';

// Invoice design slice 1 through the real stack (docs/SPEC.md § 8 row 217): the owner opens « Modèles de documents »,
// sees the company's latest invoice as Gotenberg renders it, tries another layout and another room for the logo, which
// the picture follows, and saves them. One database is shared by the whole suite: the design found at the start is put
// back at the end, and the draft and customer made for the run are cancelled and retired.
const CSRF = '0123456789abcdef0123456789abcdef';
const DESIGN = [
  'document.layout',
  'document.accent',
  'document.logo_width',
  'document.logo_height',
  'document.logo_keep_proportions',
] as const;

interface Held {
  key: string;
  company: unknown;
}

/** What the company itself holds for each design setting, `undefined` where it holds nothing of its own. */
async function heldDesign(page: Page): Promise<Held[]> {
  return page.evaluate(
    async (keys) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const rows = (await (
        await fetch(`/api/companies/${me.company.id}/settings?chain=parties`)
      ).json()) as { key: string; levels: { level: string; value: unknown }[] }[];
      return keys.map((key) => ({
        key,
        company: rows
          .find((row) => row.key === key)
          ?.levels.find((held) => held.level === 'company')?.value,
      }));
    },
    [...DESIGN],
  );
}

async function restoreDesign(page: Page, held: Held[]): Promise<void> {
  const statuses = await page.evaluate(
    async ([csrf, settings]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}/settings`;
      const answered: number[] = [];
      for (const setting of settings) {
        const response =
          setting.company === undefined
            ? await fetch(`${base}/${setting.key}?level=company`, {
                method: 'DELETE',
                headers: { 'csrf-token': csrf },
              })
            : await fetch(`${base}/${setting.key}`, {
                method: 'PUT',
                headers: { 'content-type': 'application/json', 'csrf-token': csrf },
                body: JSON.stringify({ level: 'company', value: setting.company }),
              });
        answered.push(response.status);
      }
      return answered;
    },
    [CSRF, held] as const,
  );
  for (const status of statuses) expect([200, 204]).toContain(status);
}

/** A customer and a draft invoice of one line for it, made for the run: the preview shows the latest invoice. */
async function aDraftInvoice(
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
      return { customer: customer.id, invoice: invoice.id };
    },
    [CSRF, number] as const,
  );
}

async function tidy(page: Page, made: { customer: string; invoice: string }): Promise<void> {
  const statuses = await page.evaluate(
    async ([csrf, ids]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const cancelled = await fetch(`${base}/invoices/${ids.invoice}/cancel`, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: 'null',
      });
      // The write shape has no id: a body naming one is refused.
      const { id: _, ...fields } = (await (
        await fetch(`${base}/customers/${ids.customer}`)
      ).json()) as Record<string, unknown>;
      const retired = await fetch(`${base}/customers/${ids.customer}`, {
        method: 'PUT',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({ ...fields, isActive: false }),
      });
      return [cancelled.status, retired.status];
    },
    [CSRF, made] as const,
  );
  expect(statuses).toEqual([200, 200]);
}

test('the owner tries a layout on the latest invoice and saves it', async ({ page }) => {
  await signIn(page);
  await inACompany(page, CSRF);
  const held = await heldDesign(page);
  const made = await aDraftInvoice(page, `DSG-${Date.now()}`);
  try {
    await page.goto('/company/documents');
    const picture = page.getByTestId('documents-preview-image');
    await expect(picture).toHaveAttribute('src', /^data:image\/png;base64,/, { timeout: 15_000 });
    const before = await picture.getAttribute('src');

    // Chosen with the keyboard as well as the pointer: a radio group moves with its arrows.
    await page.getByTestId('documents-layout-modern').locator('input').check();
    await expect(page.getByTestId('documents-save')).toBeEnabled();
    await expect(picture).not.toHaveAttribute('src', before ?? '', { timeout: 15_000 });
    await expect(picture).not.toHaveAttribute('aria-busy', 'true');
    expect(await wcagViolations(page)).toEqual([]);

    // The logo's room, freed from its proportions: each size is typed on its own, and the preview is asked with both.
    // The shared company may have no logo, so the picture itself may not change.
    await page.getByTestId('documents-logo-lock').click();
    await expect(page.getByTestId('documents-logo-lock')).toHaveAttribute('aria-pressed', 'false');
    await expect(page.getByTestId('documents-logo-stretched')).toBeVisible();
    const tried = page.waitForResponse((response) => {
      const url = new URL(response.url());
      return (
        url.pathname.endsWith('/invoice-design-preview') &&
        url.searchParams.get('logoWidth') === '60' &&
        url.searchParams.get('logoHeight') === '25' &&
        url.searchParams.get('logoProportions') === 'free'
      );
    });
    await page.getByTestId('documents-logo-width').fill('60');
    await page.getByTestId('documents-logo-height').fill('25');
    await expect(page.getByTestId('documents-logo-width')).toHaveValue('60');
    expect((await tried).status()).toBe(200);
    await expect(picture).not.toHaveAttribute('aria-busy', 'true');

    await page.getByTestId('documents-save').click();
    await expect(toast(page)).toContainText('Présentation des documents enregistrée.');
    await expect(page.getByTestId('documents-save')).toBeDisabled();

    await page.reload();
    await expect(page.getByTestId('documents-layout-modern').locator('input')).toBeChecked();
    await expect(page.getByTestId('documents-logo-width')).toHaveValue('60');
    await expect(page.getByTestId('documents-logo-height')).toHaveValue('25');
    await expect(page.getByTestId('documents-logo-lock')).toHaveAttribute('aria-pressed', 'false');
  } finally {
    await restoreDesign(page, held);
    await tidy(page, made);
  }
});
