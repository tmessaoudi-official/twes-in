// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { signIn } from './session';

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
