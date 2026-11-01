// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { inACompany, OPERATOR_PASSWORD, signIn } from './session';
import { sendProving } from './step-up';

// What becomes of a phone outside the ranges its country hands out is the platform's to say, and every open screen
// follows it at once (hunt-r32 freshness F1): a member's customer form, open since before the operator chose « Garder »,
// takes the number it refused, with no keystroke and no reload. The platform's setting is shared by every scenario.
const CSRF = '0123456789abcdef0123456789abcdef';
const POLICY = '/api/platform/settings/platform.phones.outside_ranges';

test.describe.configure({ mode: 'serial' });

test('a number refused on an open form is taken once the operator keeps such numbers', async ({
  browser,
}) => {
  const operator = await (await browser.newContext()).newPage();
  const member = await (await browser.newContext()).newPage();
  await signIn(operator);
  const before = await policyOf(operator);
  try {
    await writePolicy(operator, 'refuse');
    await signIn(member);
    await inACompany(member, CSRF);
    await member.goto('/customers/new');
    // A Tunisian number of the right length that no Tunisian range hands out.
    await member.getByTestId('field-phone').fill('80 123 456');
    await member.getByTestId('field-phone').press('Tab');
    await expect(member.getByTestId('field-error-phone')).toBeVisible();
    // A marker a reload would erase: the verdict must change in this very document.
    await member.evaluate(() => ((window as unknown as { liveMarker: boolean }).liveMarker = true));

    await writePolicy(operator, 'keep');

    await expect(member.getByTestId('field-error-phone')).toHaveCount(0, { timeout: 10_000 });
    expect(
      await member.evaluate(() => (window as unknown as { liveMarker?: boolean }).liveMarker),
    ).toBe(true);
    await expect(member.getByTestId('field-phone')).toHaveValue('80 123 456');
  } finally {
    await writePolicy(operator, before);
  }
});

async function policyOf(page: Page): Promise<string> {
  return page.evaluate(async () => {
    const answer = await fetch('/api/platform/settings');
    if (!answer.ok) throw new Error(`reading the platform settings answered ${answer.status}`);
    const rows = (await answer.json()) as { key: string; value: unknown }[];
    const value = rows.find((row) => row.key === 'platform.phones.outside_ranges')?.value;
    return typeof value === 'string' ? value : 'refuse';
  });
}

async function writePolicy(page: Page, value: string): Promise<void> {
  const answer = await sendProving(page, OPERATOR_PASSWORD, 'PUT', POLICY, { value });
  if (answer.status < 200 || answer.status > 299)
    throw new Error(`writing the phone policy ${value} answered ${answer.status}`);
}
