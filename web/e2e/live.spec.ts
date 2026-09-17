// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { signIn } from './session';
import { toast } from './toast';

// Live data (docs/SPEC.md § 7, 2026-09-17), through the real stack and Centrifugo: two tabs of the same company, one
// adds a customer group and the other shows it without being reloaded, then sees it go when it is deleted.
const CSRF = '0123456789abcdef0123456789abcdef';

test('a change made in one tab reaches a list open in another without a reload', async ({
  browser,
}) => {
  const name = `LIVE ${Date.now().toString(36).toUpperCase()}`;
  const writer = await (await browser.newContext()).newPage();
  const watcher = await (await browser.newContext()).newPage();
  await signIn(writer);
  await signIn(watcher);

  await watcher.goto('/customers/groups');
  await expect(watcher.getByTestId('customer-group-add')).toBeVisible();
  // A marker a reload would erase: the row must arrive in this very document.
  await watcher.evaluate(() => ((window as unknown as { liveMarker: boolean }).liveMarker = true));

  await writer.goto('/customers/groups');
  await writer.getByTestId('customer-group-add').click();
  await writer.getByTestId('field-name').fill(name);
  await writer.getByTestId('customer-group-save').click();
  await expect(writer.getByTestId(`customer-group-${name}`)).toBeVisible();

  await expect(watcher.getByTestId(`customer-group-${name}`)).toBeVisible({ timeout: 10_000 });
  await expect(watcher.getByTestId(`customer-group-${name}`)).toHaveClass(/twes-row-new/);

  await writer.evaluate(
    async ([csrf, group]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}/customer-groups`;
      const groups = (await (await fetch(base)).json()) as { id: string; name: string }[];
      const found = groups.find((row) => row.name === group);
      if (!found) throw new Error(`${group} is not listed`);
      const deleted = await fetch(`${base}/${found.id}`, {
        method: 'DELETE',
        headers: { 'csrf-token': csrf },
      });
      if (!deleted.ok) throw new Error(`deleting ${group} answered ${deleted.status}`);
    },
    [CSRF, name] as const,
  );

  await expect(watcher.getByTestId(`customer-group-${name}`)).toHaveCount(0, { timeout: 10_000 });
  expect(
    await watcher.evaluate(() => (window as unknown as { liveMarker?: boolean }).liveMarker),
  ).toBe(true);
});

/** Saves the customer numbered `number` from `page` through the API, changing `changes`, as another person would. */
async function reviseCustomer(
  page: Page,
  number: string,
  changes: Record<string, unknown>,
): Promise<void> {
  await page.evaluate(
    async ([csrf, customerNumber, fields]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}/customers`;
      const rows = (await (await fetch(base)).json()) as { id: string; number: string }[];
      const found = rows.find((row) => row.number === customerNumber);
      if (!found) throw new Error(`${customerNumber} is not listed`);
      const { id, ...saved } = found;
      const revised = await fetch(`${base}/${id}`, {
        method: 'PUT',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({ ...saved, ...fields }),
      });
      if (!revised.ok) throw new Error(`revising ${customerNumber} answered ${revised.status}`);
    },
    [CSRF, number, changes] as const,
  );
}

test('an open customer takes what another person saved, and keeps what is being typed', async ({
  browser,
}) => {
  const run = Date.now().toString(36).toUpperCase();
  const number = `LIVE-${run}`;
  const writer = await (await browser.newContext()).newPage();
  const watcher = await (await browser.newContext()).newPage();
  await signIn(writer);
  await signIn(watcher);

  await watcher.goto('/customers/new');
  await watcher.getByTestId('field-number').fill(number);
  await watcher.getByTestId('field-name').fill(`Live ${run}`);
  await watcher.getByTestId('field-identifier__matricule_fiscal').fill('1234567A/B/M/000');
  await watcher.getByTestId('customer-save').click();
  await expect(watcher).toHaveURL(/\/customers\/[0-9a-f-]{36}$/);
  await expect(watcher.getByTestId('customer-title')).toContainText(number);
  await watcher.evaluate(() => ((window as unknown as { liveMarker: boolean }).liveMarker = true));

  try {
    // Nothing typed: the saved phone arrives in place, and a toast says the record changed.
    await reviseCustomer(writer, number, { phone: '71000001' });
    await expect(watcher.getByTestId('field-phone')).toHaveValue('71000001', { timeout: 10_000 });
    await expect(toast(watcher)).toContainText('a modifié cette fiche');
    await expect(watcher.getByTestId('record-changed')).toHaveCount(0);

    // Typing the name: the typed name stays, the other field updates, and the name waits for a choice.
    await watcher.getByTestId('field-name').fill(`Mine ${run}`);
    await reviseCustomer(writer, number, { name: `Theirs ${run}`, phone: '71000002' });
    await expect(watcher.getByTestId('field-phone')).toHaveValue('71000002', { timeout: 10_000 });
    await expect(watcher.getByTestId('record-changed')).toBeVisible();
    await expect(watcher.getByTestId('field-name')).toHaveValue(`Mine ${run}`);
    await expect(watcher.getByTestId('field-conflict-name')).toContainText(`Theirs ${run}`);
    await watcher.getByTestId('field-take-theirs-name').click();
    await expect(watcher.getByTestId('field-name')).toHaveValue(`Theirs ${run}`);
    await expect(watcher.getByTestId('field-conflict-name')).toHaveCount(0);

    expect(
      await watcher.evaluate(() => (window as unknown as { liveMarker?: boolean }).liveMarker),
    ).toBe(true);
  } finally {
    await reviseCustomer(writer, number, { isActive: false });
  }
});
