// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { inACompany, signIn } from './session';
import { wcagViolations } from './axe';
import { toast } from './toast';

// G5 module registry through the real stack: in the seeded company, the owner switches the customers module off,
// its entries leave the navigation, its page sends them home and its API answers 404; switching it back on brings
// all of it back. Delivery notes and invoices need customers, so they are switched off first and back on last. The
// suite shares one database and runs serially, and every module switched off is switched on again whatever happens,
// so no other scenario ever finds one off.
const CSRF = '0123456789abcdef0123456789abcdef';

/** What the API answers for the working company's customers. */
async function customersStatus(page: Page): Promise<number> {
  return page.evaluate(async () => {
    const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
    return (await fetch(`/api/companies/${me.company.id}/customers`)).status;
  });
}

async function switchModule(page: Page, key: string, enabled: boolean): Promise<void> {
  await page.evaluate(
    async ([csrf, moduleKey, on]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const switched = await fetch(`/api/companies/${me.company.id}/modules/${moduleKey}`, {
        method: 'PUT',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({ enabled: on }),
      });
      // Left off, every later scenario of that module would fail for a reason that is not its own.
      if (!switched.ok)
        throw new Error(`switching ${moduleKey} to ${on} answered ${switched.status}`);
    },
    [CSRF, key, enabled] as const,
  );
}

test('a module switched off leaves the navigation, its pages and its API until it is switched on again', async ({
  page,
}) => {
  await signIn(page);
  try {
    await switchModule(page, 'invoices', false);
    await switchModule(page, 'delivery_notes', false);
    await switchModule(page, 'price_lists', false);
    await switchModule(page, 'quotes', false);
    await page.goto('/company/modules');
    await expect(page.getByTestId('nav-customers')).toBeVisible();
    expect(await wcagViolations(page)).toEqual([]);

    await page.getByTestId('module-toggle-customers').getByRole('switch').click();
    await expect(page.getByTestId('nav-customers')).toHaveCount(0);
    expect(await customersStatus(page)).toBe(404);
    await page.goto('/customers');
    await expect(page).toHaveURL(/\/$/);

    await page.goto('/company/modules');
    await page.getByTestId('module-toggle-customers').getByRole('switch').click();
    await expect(page.getByTestId('nav-customers')).toBeVisible();
    expect(await customersStatus(page)).toBe(200);
  } finally {
    await switchModule(page, 'customers', true);
    await switchModule(page, 'delivery_notes', true);
    await switchModule(page, 'price_lists', true);
    // Invoices, delivery notes and quotes were switched off first, and need customers back before they come back on.
    await switchModule(page, 'invoices', true);
    await switchModule(page, 'quotes', true);
  }
});

// docs/SPEC.md § 7, 2026-10-06 21:02: the scanner is a module. Off, the bar offers no camera and no phone, and the
// API opens no pairing; on again, both come back.
test('the scanner switched off offers no camera nor phone and opens no pairing, until it is on again', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  const pairing = () =>
    page.evaluate(async (csrf) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const opened = await fetch(`/api/companies/${me.company.id}/scan-pairings`, {
        method: 'POST',
        headers: { 'csrf-token': csrf, 'x-tab': 'e2e-scanner-module' },
      });
      if (opened.ok) {
        const { id } = (await opened.json()) as { id: string };
        await fetch(`/api/companies/${me.company.id}/scan-pairings/${id}`, {
          method: 'DELETE',
          headers: { 'csrf-token': csrf },
        });
      }
      return opened.status;
    }, CSRF);
  try {
    await page.goto('/');
    await expect(page.getByTestId('phone-pair')).toBeVisible();
    await switchModule(page, 'scanning', false);
    await page.reload();
    await expect(page.getByTestId('nav-customers')).toBeVisible();
    await expect(page.getByTestId('phone-pair')).toHaveCount(0);
    await expect(page.getByTestId('camera-open')).toHaveCount(0);
    expect(await pairing()).toBe(404);
    await page.screenshot({ path: test.info().outputPath('scanner-off.png') });
  } finally {
    await switchModule(page, 'scanning', true);
  }
  await page.reload();
  await expect(page.getByTestId('phone-pair')).toBeVisible();
  expect(await pairing()).toBe(201);
});

async function setInterest(page: Page, key: string, interested: boolean): Promise<void> {
  await page.evaluate(
    async ([csrf, moduleKey, wanted]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const answered = await fetch(
        `/api/companies/${me.company.id}/modules/${moduleKey}/interest`,
        {
          method: 'PUT',
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify({ interested: wanted }),
        },
      );
      // Left asked for, the operator's demand would count this run in every later one.
      if (!answered.ok)
        throw new Error(`« Me prévenir » on ${moduleKey} answered ${answered.status}`);
    },
    [CSRF, key, interested] as const,
  );
}

// « Me prévenir » (docs/SPEC.md § 7, 2026-09-26 10:08, row 150): the owner asks to be told when a planned module
// arrives, the operator (the same seeded person) reads the demand, and the owner stops asking. Whatever happens, the
// interest is withdrawn, so the shared database waits for nothing afterwards.
test('a company asks to be told when a planned module arrives, the operator reads it, and it can stop asking', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  try {
    await page.goto('/company/modules');
    const notify = page.getByTestId('module-notify-zakat');
    await expect(notify).toHaveText(/Me prévenir|Notify me/);

    await notify.click();
    await expect(toast(page)).toContainText(/Zakat/);
    await expect(page.getByTestId('module-notified-zakat')).toBeVisible();
    await expect(notify).toHaveText(/Ne plus me prévenir|Stop notifying me/);
    expect(await wcagViolations(page)).toEqual([]);

    await page.goto('/platform?tab=demand');
    await expect(page.getByTestId('demand-zakat')).toContainText(/Zakat/);

    await page.goto('/company/modules');
    await page.getByTestId('module-notify-zakat').click();
    await expect(page.getByTestId('module-notified-zakat')).toHaveCount(0);
    await expect(page.getByTestId('module-notify-zakat')).toHaveText(/Me prévenir|Notify me/);
  } finally {
    await setInterest(page, 'zakat', false);
  }
});

// A value of the wrong type answers 422 with its violations (API Platform 5; it was 400 before), through the real
// session and CSRF header a screen uses. Nothing is switched: the refusal happens before any module changes.
test('a wrong-typed value is refused with 422 and names the field, and changes nothing', async ({
  page,
}) => {
  await signIn(page);
  const answer = await page.evaluate(async (csrf) => {
    const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
    const refused = await fetch(`/api/companies/${me.company.id}/modules/customers`, {
      method: 'PUT',
      headers: { 'content-type': 'application/json', 'csrf-token': csrf },
      body: JSON.stringify({ enabled: 'no' }),
    });
    return { status: refused.status, body: await refused.text() };
  }, CSRF);
  expect(answer.status).toBe(422);
  expect(answer.body).toContain('enabled');
  expect(await customersStatus(page)).toBe(200);
});
