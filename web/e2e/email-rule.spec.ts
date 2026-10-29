// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { inACompany, signIn } from './session';

const CSRF = '0123456789abcdef0123456789abcdef';

// One rule for every address a person writes (`ContactRules::email` in the API, `shared/form/email-rule.ts` here): an
// address whose domain has no dot is said wrong under its field, with the format and an example, before anything is
// sent; a slip of a key in a common domain is offered beside the field, never written into it. Nothing is written.
test('a member’s address the API would refuse is said under its field, and a slip of a key is offered', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await page.getByTestId('nav-settings').click();
  await page.getByTestId('nav-members').click();
  let sent = false;
  page.on('request', (request) => {
    if (request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/members'))
      sent = true;
  });

  await page.getByTestId('member-email').fill('joiner@exemple');
  await page.getByTestId('member-add').click();
  await expect(page.getByTestId('member-email-error')).toContainText('@');
  expect(sent).toBe(false);

  await page.getByTestId('member-email').fill('joiner@gmial.com');
  await expect(page.getByTestId('member-email-typo')).toContainText('joiner@gmail.com');
  await expect(page.getByTestId('member-email')).toHaveValue('joiner@gmial.com');
});

test('an address the legal pages name is said wrong before the operator is asked who is at the screen', async ({
  page,
}) => {
  await signIn(page);
  await page.goto('/platform/legal');
  let asked = false;
  page.on('request', (request) => {
    if (request.method() === 'PUT' && new URL(request.url()).pathname === '/api/platform/settings')
      asked = true;
  });

  await page.getByTestId('legal-identity-privacy.email').fill('dpo@twes');
  await page.getByTestId('legal-identity-save').click();

  await expect(page.getByTestId('legal-identity-privacy.email-error')).toContainText('@');
  await expect(page.getByTestId('legal-identity-privacy.email')).toBeFocused();
  expect(asked).toBe(false);
});
