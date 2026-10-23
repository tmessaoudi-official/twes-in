// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { signIn } from './session';

// The platform's cards say on the field itself why what is typed cannot be saved, rather than only greying
// « Enregistrer »; nothing is written, so the shared database keeps what it held.
test('a count typed out of its bounds or emptied is said on its own field', async ({ page }) => {
  await signIn(page);
  await page.goto('/platform');

  const rows = page.getByTestId('platform-import-rows');
  await expect(rows).toBeVisible();
  const held = await rows.inputValue();

  await rows.fill('');
  await expect(page.getByTestId('platform-import-rows-error')).toHaveText('Indiquez un nombre.');
  await expect(rows).toHaveAttribute('aria-invalid', 'true');
  await expect(page.getByTestId('platform-import-save')).toBeDisabled();

  await rows.fill('0');
  await expect(page.getByTestId('platform-import-rows-error')).toContainText(
    'Attendu : un nombre entier de',
  );

  await rows.fill(held);
  await expect(page.getByTestId('platform-import-rows-error')).toHaveCount(0);
  await expect(rows).toHaveAttribute('aria-invalid', 'false');
});
