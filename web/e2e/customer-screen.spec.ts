// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { aProduct, forget } from './catalogue';
import { inACompany, OPERATOR_PASSWORD, signIn } from './session';

// The customer screen stands at one establishment and says that establishment's stock (docs/SPEC.md row 207). An
// establishment can never be removed and the suite shares one database, so the second one is answered by the route
// rather than added to Demo: what this certifies is the screen asking, remembering and sending the place it stands
// at to the real API, which reads the stock there; the stock of each place is certified by CustomerScreenAvailabilityTest.
const CSRF = '0123456789abcdef0123456789abcdef';

test('the customer screen asks where it stands, says that place, remembers it, holds the sign-in and is left with the password', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  const reference = `ECR-${Date.now()}`;
  const product = await aProduct(page, reference);

  const real = await page.evaluate(async () => {
    const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
    const rows = (await (await fetch(`/api/companies/${me.company.id}/establishments`)).json()) as {
      id: string;
      code: string;
      name: string;
      isDefault: boolean;
    }[];
    const main = rows.find((row) => row.isDefault);
    if (main === undefined) throw new Error('the company has no default establishment');
    return main;
  });
  await page.route('**/api/companies/*/establishments', async (route) => {
    const response = await route.fetch();
    const rows = (await response.json()) as object[];
    await route.fulfill({
      response,
      json:
        rows.length > 1
          ? rows
          : [
              ...rows,
              {
                ...rows[0],
                id: '019a0000-0000-7000-8000-000000000207',
                code: '207',
                name: 'Agence e2e',
                isDefault: false,
              },
            ],
    });
  });

  try {
    await page.goto('/customer-screen');
    await expect(page.getByTestId('customer-screen-places')).toBeVisible();
    // The window faces customers: it names the company, under the lock (audit 2026-10-06, V-33).
    await expect(page.getByTestId('company-brand-name')).not.toBeEmpty();
    await expect(page.getByTestId('customer-screen-form')).toBeHidden();
    await page.screenshot({ path: test.info().outputPath('customer-screen-places.png') });
    await page.getByTestId(`customer-screen-place-${real.code}`).click();
    await expect(page.getByTestId('customer-screen-place')).toContainText(real.name);
    await expect(page.getByTestId('customer-screen-words')).toBeFocused();

    const asked = page.waitForRequest((request) =>
      request.url().includes('/customer-screen/availability'),
    );
    await page.getByTestId('customer-screen-words').fill(reference);
    await page.getByTestId('customer-screen-words').press('Enter');
    expect(new URL((await asked).url()).searchParams.get('establishmentId')).toBe(real.id);
    await expect(page.getByTestId('customer-screen-name')).toContainText(reference);
    await page.screenshot({ path: test.info().outputPath('customer-screen-found.png') });

    // The device remembers where it stands: opened again, it asks nothing.
    await page.reload();
    await expect(page.getByTestId('customer-screen-place')).toContainText(real.name);
    await expect(page.getByTestId('customer-screen-places')).toBeHidden();
    await page.getByTestId('customer-screen-place-change').click();
    await expect(page.getByTestId('customer-screen-places')).toBeVisible();
    await page.getByTestId(`customer-screen-place-${real.code}`).click();

    // The API holds the whole sign-in, not this tab: another tab lands on the screen, and a typed call is refused.
    const other = await page.context().newPage();
    await other.goto('/customers');
    await expect(other).toHaveURL(/\/customer-screen$/);
    await other.close();
    const refused = await page.evaluate(async () => {
      const me = (await (await fetch('/api/auth/me')).json()) as {
        company: { id: string };
        customerScreenCompanyId: string | null;
      };
      const answer = await fetch(`/api/companies/${me.company.id}/customers`);
      return { held: me.customerScreenCompanyId, status: answer.status };
    });
    expect(refused).toEqual({ held: expect.any(String), status: 403 });

    // Leaving it takes the password again.
    await page.getByTestId('customer-screen-leave').click();
    await expect(page.getByTestId('step-up-title')).toBeVisible();
    await page.getByTestId('step-up-password').fill(OPERATOR_PASSWORD);
    await page.getByTestId('step-up-confirm').click();
    await expect(page).toHaveURL(/\/$/);
  } finally {
    // The operator's session is shared by every scenario after this one: a failure above must not leave it held.
    await page.evaluate(
      async ([token, password]) => {
        const me = (await (await fetch('/api/auth/me')).json()) as {
          customerScreenCompanyId: string | null;
        };
        if (me.customerScreenCompanyId === null) return;
        const headers = { 'content-type': 'application/json', 'csrf-token': token };
        const proved = await fetch('/api/auth/step-up', {
          method: 'POST',
          headers,
          body: JSON.stringify({ password }),
        });
        if (!proved.ok) throw new Error(`the step-up answered ${proved.status}`);
        const left = await fetch('/api/auth/customer-screen', { method: 'DELETE', headers });
        if (!left.ok) throw new Error(`leaving the customer screen answered ${left.status}`);
      },
      [CSRF, OPERATOR_PASSWORD] as const,
    );
    await page.unroute('**/api/companies/*/establishments');
    await forget(page, [product]);
  }
});
