// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test } from '@playwright/test';
import { OPERATOR_PASSWORD, inACompany, signIn } from './session';
import { proveIfHeldBack, sendProving } from './step-up';
import { toast } from './toast';

const CSRF = '0123456789abcdef0123456789abcdef';

/**
 * Nothing brings a deleted role back, so « Supprimer le rôle » asks first, naming it, and deletes it on the version the
 * list read. The role is the run's own, made through the API, and gone at the end.
 */
test('a role is deleted only once its question, naming it, is answered', async ({ page }) => {
  await signIn(page);
  await inACompany(page, CSRF);
  const companyId = await page.evaluate(async () => {
    const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
    return me.company.id;
  });
  const name = `essai-${Date.now()}`;
  const made = await sendProving(
    page,
    OPERATOR_PASSWORD,
    'POST',
    `/api/companies/${companyId}/roles`,
    {
      name,
      permissions: ['customer.read'],
    },
  );
  expect(made.status).toBe(201);

  await page.goto('/company/roles');
  await expect(page.getByTestId(`role-${name}`)).toBeVisible();

  // Kept: the question's « Garder le rôle » leaves it where it was.
  await page.getByTestId(`role-delete-${name}`).click();
  await expect(page.getByTestId('confirm-message')).toContainText(name);
  await page.getByTestId('confirm-keep').click();
  // The question has left the page, closing animation included, before it is asked again.
  await expect(page.getByTestId('confirm-message')).toHaveCount(0);
  await expect(page.getByTestId(`role-${name}`)).toBeVisible();

  const deleted = page.waitForResponse(
    (response) => response.request().method() === 'DELETE' && response.url().includes(`/roles/`),
  );
  await page.getByTestId(`role-delete-${name}`).click();
  await page.getByTestId('confirm-run').click();
  expect((await proveIfHeldBack(page, OPERATOR_PASSWORD, deleted)).status()).toBe(204);
  await expect(toast(page)).toContainText('Rôle supprimé');
  await expect(page.getByTestId(`role-${name}`)).toHaveCount(0);
});
