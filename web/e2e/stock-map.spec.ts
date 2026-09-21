// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { inACompany, signIn } from './session';
import { toast } from './toast';

// Row 83's drawn stock map through the real stack: the owner adds a floor of the default establishment, draws a
// rack on it in metres, reads the rectangle back off the plan, moves it, and erases it. One database is shared by
// the whole suite, so the floor and the location are unique to the run and are taken away afterwards — a rectangle
// erased and a location that never moved goods can both be deleted, which is what keeps this from leaking a row.
const CSRF = '0123456789abcdef0123456789abcdef';

interface Fixture {
  companyId: string;
  establishmentId: string;
  locationId: string;
  code: string;
  /** The lowest level this establishment has free, so the run never clashes with an existing floor. */
  level: number;
}

/** A rack under the default establishment's own location, through the API. */
async function prepare(page: Page, code: string): Promise<Fixture> {
  return page.evaluate(
    async ([csrf, locationCode]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const send = async (method: string, url: string, body: unknown): Promise<unknown> => {
        const response = await fetch(url, {
          method,
          headers: { 'content-type': 'application/json', 'csrf-token': csrf },
          body: JSON.stringify(body),
        });
        if (!response.ok) throw new Error(`${method} ${url} answered ${response.status}`);
        return response.json();
      };
      const options = (await (await fetch(`${base}/stock-options`)).json()) as {
        establishments: { id: string; code: string; name: string }[];
      };
      const establishment = options.establishments[0];
      if (!establishment) throw new Error('the company has no establishment');
      const location = (await send('POST', `${base}/stock-locations`, {
        establishmentId: establishment.id,
        parentId: null,
        kind: 'rack',
        code: locationCode,
        name: `Rayonnage ${locationCode}`,
      })) as { id: string };

      // A level is a whole number from 0 to 200 and no two floors of an establishment share one, so the run takes
      // the lowest free one rather than a number off the clock — which would be refused above 200 and would clash
      // with whatever an earlier run left behind.
      const floors = (await (await fetch(`${base}/stock-floors`)).json()) as { level: number }[];
      const taken = new Set(floors.map((floor) => floor.level));
      let level = 0;
      while (taken.has(level) && level <= 200) level += 1;
      if (level > 200) throw new Error('the establishment has no free floor level');

      return {
        companyId: me.company.id,
        establishmentId: establishment.id,
        locationId: location.id,
        code: locationCode,
        level,
      };
    },
    [CSRF, code] as const,
  );
}

/** Takes the run's own rows away: the rectangle first, then the location, then the floor it was drawn on. */
async function clean(page: Page, fixture: Fixture, floorName: string): Promise<void> {
  await page.evaluate(
    async ([csrf, { companyId, locationId }, name]) => {
      const base = `/api/companies/${companyId}`;
      const drop = async (url: string): Promise<void> => {
        const response = await fetch(url, { method: 'DELETE', headers: { 'csrf-token': csrf } });
        // 404 is fine: the case may already have erased it. Anything else is a leak worth failing on.
        if (!response.ok && response.status !== 404) {
          throw new Error(`DELETE ${url} answered ${response.status}`);
        }
      };
      const floors = (await (await fetch(`${base}/stock-floors`)).json()) as {
        id: string;
        name: string;
      }[];
      const drawings = (
        await Promise.all(
          floors
            .filter((floor) => floor.name === name)
            .map(async (floor) => {
              const rows = (await (
                await fetch(`${base}/stock-floors/${floor.id}/drawings`)
              ).json()) as { id: string }[];
              return rows;
            }),
        )
      ).flat();
      for (const drawing of drawings) await drop(`${base}/stock-drawings/${drawing.id}`);
      await drop(`${base}/stock-locations/${locationId}`);
      for (const floor of floors.filter((one) => one.name === name)) {
        await drop(`${base}/stock-floors/${floor.id}`);
      }
    },
    [CSRF, fixture, floorName] as const,
  );
}

test.describe('the drawn stock map', () => {
  test('adds a floor, draws a rack on it in metres, moves it and erases it', async ({ page }) => {
    const stamp = Date.now().toString().slice(-8);
    const code = `MAP${stamp}`;
    const floorName = `Étage ${stamp}`;

    await signIn(page);
    await inACompany(page, CSRF);
    const fixture = await prepare(page, code);

    try {
      await page.goto('/stock/plan');
      await expect(page.getByTestId('stock-map-title')).toBeVisible();

      // A floor of the establishment, at a level no other floor of this run occupies.
      await page.getByTestId('stock-floor-add').click();
      await page.getByTestId('field-name').fill(floorName);
      await page.getByTestId('field-level').fill(String(fixture.level));
      await page.getByTestId('stock-floor-save').click();
      await expect(toast(page)).toContainText('Étage enregistré');

      await page.getByRole('button', { name: floorName, exact: true }).click();

      // The rack, drawn by the form rather than by dragging: the form is the way in, on every window.
      await page.getByTestId('stock-drawing-add').click();
      await page.getByTestId('field-locationId').click();
      await page.getByRole('option', { name: new RegExp(code) }).click();
      await page.getByTestId('field-x').fill('2,6');
      await page.getByTestId('field-y').fill('4');
      await page.getByTestId('field-width').fill('3,9');
      await page.getByTestId('field-depth').fill('0,6');
      await page.getByTestId('field-height').fill('2,1');
      await page.getByTestId('stock-drawing-save').click();
      await expect(toast(page)).toContainText('Rectangle enregistré');

      // Read back off the plan: 2,6 m was typed and the grid is a quarter of a metre, so it sits at 2,5.
      const drawn = page.getByTestId(`stock-drawing-${code}`);
      await expect(drawn).toBeVisible();
      await expect(drawn).toContainText('3.9 × 0.6 m');
      await expect(page.locator('svg[data-testid="stock-map-svg"] rect')).toHaveAttribute(
        'x',
        '2.5',
      );

      // Moved, not drawn a second time: a rack is in one place, so the plan still carries one rectangle.
      await drawn.click();
      await page.getByTestId('stock-drawing-edit').click();
      await page.getByTestId('field-x').fill('5');
      await page.getByTestId('stock-drawing-save').click();
      await expect(toast(page)).toContainText('Rectangle enregistré');
      await expect(page.locator('svg[data-testid="stock-map-svg"] rect')).toHaveCount(1);
      await expect(page.locator('svg[data-testid="stock-map-svg"] rect')).toHaveAttribute('x', '5');

      // Erased: the rectangle goes, the rack stays a rack.
      await page.getByTestId(`stock-drawing-${code}`).click();
      await page.getByTestId('stock-drawing-erase').click();
      await expect(toast(page)).toContainText('Rectangle effacé');
      await expect(page.getByTestId('stock-map-empty')).toBeVisible();

      await page.goto('/stock/locations');
      await expect(page.getByTestId(`stock-location-${code}`)).toBeVisible();
    } finally {
      // A timed-out case closes its page, and cleaning a closed page throws over the failure that caused it —
      // which reads as a cleanup bug and hides the real one. Measured: it cost a whole CI round to see through.
      if (!page.isClosed()) await clean(page, fixture, floorName);
    }
  });
});
