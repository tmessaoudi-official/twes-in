// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { chooseLanguage } from './language';
import { forgetPresentationChoices } from './presentation';
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

// A select whose label sits on its top edge is at least as wide as that label where it may grow: « Numéro hors des
// plages » once wrapped onto two lines inside a box sized to « Refuser » and hid the value. One line, inside the box,
// above the value, on a laptop, on a phone without a sideways scroll, and turned over in Arabic.
test.describe("a label on a select's edge", () => {
  test.afterEach(async ({ page }) => {
    await forgetPresentationChoices(page);
  });

  for (const [width, language] of [
    [1440, 'fr'],
    [390, 'fr'],
    [1440, 'ar'],
  ] as const) {
    test(`reads on one line above its value at ${width} px in ${language}`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await signIn(page);
      await page.goto('/platform');
      if (language !== 'fr') await chooseLanguage(page, language);
      const select = page.getByTestId('platform-phones').locator('app-select');
      await expect(select).toBeVisible();
      // Every box is read in one frame, once the field has stopped moving: a language change turns the page over and
      // slides it for a moment, and boxes read one call apart compared two frames.
      const frame = () =>
        select.evaluate((host) => {
          const rect = (selector: string) => {
            const { x, y, width, height } = host.querySelector(selector)!.getBoundingClientRect();
            return { x, y, width, height };
          };
          const label = host.querySelector('span[id$="-label"]')!;
          return {
            box: rect('[role="combobox"]'),
            said: rect('span[id$="-label"]'),
            value: rect('.twes-value'),
            cut: label.scrollWidth - label.clientWidth,
          };
        });
      let measured = await frame();
      await expect
        .poll(async () => {
          const before = measured.box.x;
          measured = await frame();
          return measured.box.x === before;
        })
        .toBe(true);
      const { box, said, value, cut } = measured;

      expect(said.height, 'the label takes one line').toBeLessThanOrEqual(20);
      expect(cut, 'the label shows whole where its box may grow').toBeLessThanOrEqual(0);
      expect(said.x, 'the label starts inside the box').toBeGreaterThanOrEqual(box.x);
      expect(said.x + said.width, 'the label ends inside the box').toBeLessThanOrEqual(
        box.x + box.width,
      );
      expect(said.y + said.height, 'the label stays above the value').toBeLessThanOrEqual(
        value.y + 1,
      );
      const sideways = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      );
      expect(sideways, 'nothing scrolls sideways').toBeLessThanOrEqual(0);
    });
  }
});
