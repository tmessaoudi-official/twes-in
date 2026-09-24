// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { aProduct, forget, gtin, withCodes } from './catalogue';
import { inACompany, signIn } from './session';

// docs/SPEC.md § 7, 2026-09-23 09:45, slice 4: a phone lent to a computer tab as a scanner. The phone is another
// browser with no session at all; it claims the link the computer shows, and each code it sends acts once on the
// computer. The computer's echo reaches the phone through Centrifugo, so the whole path — the public API, the
// realtime channels in both directions and the choices sent back — is exercised.
const CSRF = '0123456789abcdef0123456789abcdef';

test('a phone scans for the computer with no sign-in, sees what it did, and its taps act there', async ({
  page,
  browser,
}) => {
  const run = Date.now().toString(36).toUpperCase();
  const code = gtin(`201${String(Date.now() % 1_000_000_000).padStart(9, '0')}`);
  await signIn(page);
  await inACompany(page, CSRF);
  const ids: string[] = [];
  // Locally the link names the stack's HTTPS address on the network (`make up`, the lan service), whose certificate
  // comes from Caddy's own authority; in CI there is none and the link is the tab's own address.
  const phone = await browser.newContext({ ignoreHTTPSErrors: true });
  try {
    ids.push(await aProduct(page, `PHN-${run}`));
    await withCodes(page, ids[0], [code]);

    await page.goto('/invoices/new');
    await expect(page.getByTestId('line-0-description')).toBeVisible();
    await page.getByTestId('phone-pair').click();
    const url = (await page.getByTestId('phone-pair-url').textContent())?.trim() ?? '';
    expect(url).toMatch(/\/pair#[0-9a-f]{64}$/);
    await expect(page.getByTestId('phone-pair-status')).toContainText('En attente du téléphone');

    const screen = await phone.newPage();
    await screen.goto(url);
    await expect(screen.getByTestId('phone-code')).toBeVisible();
    // The link is off the phone's address the moment it is read.
    expect(screen.url()).not.toContain('#');
    await expect(page.getByTestId('phone-pair-status')).toContainText('Téléphone relié');
    expect(await wcagViolations(page)).toEqual([]);
    await page.getByTestId('phone-pair-close').click();

    await screen.getByTestId('phone-code').fill(code);
    await screen.getByTestId('phone-send').click();

    await expect(page.getByTestId('line-0-description')).toHaveValue(`Vis PHN-${run}`);
    await expect(page.getByTestId('line-0-quantity')).toHaveValue('1');
    await expect(screen.getByTestId('phone-echo')).toContainText(`Vis PHN-${run}`);
    // The customer price as the computer writes it; the cost is never sent.
    await expect(screen.getByTestId('phone-echo')).toContainText('1,000');
    expect(await wcagViolations(screen)).toEqual([]);

    // A code nobody holds opens the computer's card, whose choices the phone offers; a tap acts on the computer.
    const unknown = `NEW-${run}`;
    await screen.getByTestId('phone-code').fill(unknown);
    await screen.getByTestId('phone-send').click();
    await expect(page.getByTestId('product-scan-none')).toBeVisible();
    await screen.getByTestId('phone-choice-create').click();
    // The sale on the computer holds a scanned line, so leaving it for the new product asks there first (RCH-01).
    await page.getByTestId('confirm-run').click();
    await expect(page).toHaveURL(new RegExp(`/products/new\\?barcode=${unknown}`));

    // Letting the phone go on the computer stops it.
    await page.getByTestId('phone-pair').click();
    await page.getByTestId('phone-pair-end').click();
    await expect(screen.getByTestId('phone-ended')).toBeVisible();
  } finally {
    await phone.close();
    await forget(page, ids);
  }
});

test('a link is claimed by one phone only', async ({ page, browser }) => {
  await signIn(page);
  await inACompany(page, CSRF);
  const first = await browser.newContext({ ignoreHTTPSErrors: true });
  const second = await browser.newContext({ ignoreHTTPSErrors: true });
  try {
    await page.goto('/');
    await page.getByTestId('phone-pair').click();
    const url = (await page.getByTestId('phone-pair-url').textContent())?.trim() ?? '';

    const one = await first.newPage();
    await one.goto(url);
    await expect(one.getByTestId('phone-code')).toBeVisible();
    const two = await second.newPage();
    await two.goto(url);

    await expect(two.getByTestId('phone-refused')).toContainText('déjà été utilisé');
    await page.getByTestId('phone-pair-end').click();
  } finally {
    await first.close();
    await second.close();
  }
});
