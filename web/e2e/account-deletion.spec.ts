// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test, type Locator, type Page } from '@playwright/test';
import { invitationTokenFor } from './mailpit';
import { OPERATOR_PASSWORD, inACompany, signIn as signInAsOperator } from './session';
import { proveIfAsked } from './step-up';
import { toast } from './toast';

const CSRF = '0123456789abcdef0123456789abcdef';
const PASSWORD = 'a-long-enough-password';

/** The danger colour as the page resolves it now, in whichever scheme is showing. */
async function dangerColour(page: Page): Promise<string> {
  return page.evaluate(() => {
    const probe = document.createElement('span');
    probe.style.color = 'var(--mat-sys-error)';
    document.body.append(probe);
    const colour = getComputedStyle(probe).color;
    probe.remove();
    return colour;
  });
}

/** Whether a colour is the danger colour now, and the scheme has left the one whose danger colour was `before`. */
async function inDanger(page: Page, colour: string, before: string): Promise<string> {
  const danger = await dangerColour(page);
  return colour === danger && danger !== before
    ? 'in danger'
    : `${colour} against ${danger}, before ${before}`;
}

/** What the eye sees of a button: its label's colour, and its fill when it has one. */
async function drawn(button: Locator): Promise<{ label: string; fill: string }> {
  return button.evaluate((element) => {
    const label = element.querySelector('.mdc-button__label') ?? element;
    return {
      label: getComputedStyle(label).color,
      fill: getComputedStyle(element).backgroundColor,
    };
  });
}

/**
 * « Supprimer mon compte » (docs/SPEC.md § 7, 2026-10-13 12:31, 16:43, 17:01): a member of Demo invited for the run, never
 * Demo's owner, deletes their own account. The button and the confirming one are drawn in the danger colour as the
 * browser computes it, in both schemes, since a class on the element proved nothing once; the word must be typed before
 * the confirmation answers; and signing in again within the delay takes the deletion back.
 */
test('a member deletes their own account behind a red button and a typed word, and takes it back by signing in', async ({
  page,
  request,
}) => {
  const address = `leaving-${Date.now()}@twes.local`;
  await signInAsOperator(page);
  await inACompany(page, CSRF);
  await page.getByTestId('nav-settings').click();
  await page.getByTestId('nav-members').click();
  await page.getByTestId('member-email').fill(address);
  await page.getByTestId('member-add').click();
  await proveIfAsked(page, OPERATOR_PASSWORD, toast(page));
  await expect(toast(page)).toContainText('invitation');
  const invitation = await invitationTokenFor(request, address);

  await page.context().clearCookies();
  await page.goto(`/invitations/${invitation}`);
  await page.getByTestId('invitation-name').fill('Membre Sortant');
  await page.getByTestId('invitation-password').fill(PASSWORD);
  await page.getByTestId('invitation-submit').click();
  await expect(page).toHaveURL(/\/login$/);
  await page.getByTestId('email').fill(address);
  await page.getByTestId('password').fill(PASSWORD);
  await page.getByTestId('submit').click();
  await expect(page).toHaveURL(/\/$/);

  await page.goto('/account');
  const remove = page.getByTestId('account-delete');
  await remove.scrollIntoViewIfNeeded();
  // The page takes up the device's scheme a moment after it changes, so the danger colour is read on every poll beside
  // the button's, and must differ from the scheme before: read once, both would still be light in the dark pass.
  let before = '';
  for (const scheme of ['light', 'dark'] as const) {
    await page.emulateMedia({ colorScheme: scheme });
    await expect
      .poll(async () => inDanger(page, (await drawn(remove)).label, before))
      .toBe('in danger');
    before = await dangerColour(page);
  }
  await page.emulateMedia({ colorScheme: 'light' });
  await page.screenshot({ path: test.info().outputPath('account-delete-button.png') });

  await remove.click();
  const typed = page.getByTestId('confirm-typed');
  const run = page.getByTestId('confirm-run');
  await expect(typed).toBeFocused();
  await expect(page.getByTestId('confirm-kind')).toHaveAttribute('data-kind', 'definitif_a_terme');
  await expect(run).toBeDisabled();
  await typed.fill('SUPPRIM');
  await expect(run).toBeDisabled();
  await typed.fill('supprimer');
  await expect(run).toBeEnabled();
  await expect.poll(async () => inDanger(page, (await drawn(run)).fill, '')).toBe('in danger');
  await page.screenshot({ path: test.info().outputPath('account-delete-dialog.png') });
  await run.click();

  await page.getByTestId('step-up-password').fill(PASSWORD);
  await page.getByTestId('step-up-confirm').click();
  await expect(page).toHaveURL(/\/login/);
  await expect(toast(page)).toBeVisible();

  // Signing in again within the delay takes the deletion back.
  await page.getByTestId('email').fill(address);
  await page.getByTestId('password').fill(PASSWORD);
  await page.getByTestId('submit').click();
  await expect(page).toHaveURL(/\/$/);
});

test('an operator is told at once that their account is not deleted from here', async ({
  page,
}) => {
  await signInAsOperator(page);
  await page.goto('/account');

  await expect(page.getByTestId('account-delete-operator')).toBeVisible();
  await expect(page.getByTestId('account-delete')).toHaveCount(0);
});
