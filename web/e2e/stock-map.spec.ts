// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { inACompany, signIn } from './session';
import { wcagViolations } from './axe';
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

/** A measurement as the screen shows it — the locale's comma — read back as a number. */
const comma = (shown: string): number => Number(shown.replace(',', '.'));

/**
 * A floor's button by its name. Its accessible name carries its level and what is drawn on it after the name, so an
 * exact match would miss it; the name is the start of it.
 */
const floorButton = (name: string): RegExp =>
  new RegExp(`^${name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b`);

/** A rack under the default establishment's own location, through the API. */
/**
 * What does not fit in the full screen's bar: a control past the window's edge, over its neighbour, or whose label
 * runs onto a second line. Each is named with what is wrong, so a failure says which control and how.
 */
async function barMisfits(page: Page): Promise<string[]> {
  return page.getByTestId('stock-map-bar').evaluate((bar) => {
    const width = document.documentElement.clientWidth;
    const shown = [...bar.querySelectorAll<HTMLElement>('button, mat-form-field')].filter(
      (el) => el.offsetParent !== null && !el.closest('mat-form-field button'),
    );
    const misfits: string[] = [];
    const boxes = shown.map((el) => ({
      name: el.textContent!.trim().replace(/\s+/g, ' '),
      box: el.getBoundingClientRect(),
      el,
    }));
    for (const { name, box, el } of boxes) {
      if (box.left < 0 || box.right > width + 0.5) misfits.push(`${name}: outside the window`);
      // The words alone, not the icon's glyph, which sits on a baseline of its own.
      const tops: number[] = [];
      const words = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
      for (let node = words.nextNode(); node !== null; node = words.nextNode()) {
        if (node.parentElement?.closest('mat-icon') || node.textContent!.trim() === '') continue;
        const range = document.createRange();
        range.selectNodeContents(node);
        for (const rect of range.getClientRects()) if (rect.width > 0) tops.push(rect.top);
      }
      const lines = tops.filter((top, i) =>
        tops.slice(0, i).every((other) => Math.abs(other - top) > 8),
      ).length;
      if (el.tagName === 'BUTTON' && lines > 1) misfits.push(`${name}: on ${lines} lines`);
    }
    for (const [i, a] of boxes.entries()) {
      for (const b of boxes.slice(i + 1)) {
        const over = Math.min(a.box.right, b.box.right) - Math.max(a.box.left, b.box.left);
        const under = Math.min(a.box.bottom, b.box.bottom) - Math.max(a.box.top, b.box.top);
        if (over > 0.5 && under > 0.5 && !a.el.contains(b.el) && !b.el.contains(a.el))
          misfits.push(`${a.name} over ${b.name}`);
      }
    }
    return misfits;
  });
}

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

/**
 * Takes the run's own rows away: the rectangles first, then the locations, then the floor they were drawn on.
 *
 * `sweep` is the prefix of the codes a case CREATED beyond the one `prepare()` made — a repeat makes locations as
 * well as rectangles, and one left behind is a row leaked into a database the whole suite shares. It is passed in
 * rather than derived from the fixture's code: stripping the trailing digits off `REP123456781` gives `REP`, which
 * would sweep every location of every past run and, one day, a real one.
 */
async function clean(
  page: Page,
  fixture: Fixture,
  floorName: string,
  sweep?: string,
): Promise<void> {
  await page.evaluate(
    async ([csrf, { companyId, locationId }, name, prefix]) => {
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
      if (prefix !== undefined && prefix !== '') {
        const locations = (await (await fetch(`${base}/stock-locations`)).json()) as {
          id: string;
          code: string;
        }[];
        for (const one of locations.filter((row) => row.code.startsWith(prefix))) {
          await drop(`${base}/stock-locations/${one.id}`);
        }
      }
      await drop(`${base}/stock-locations/${locationId}`);
      for (const floor of floors.filter((one) => one.name === name)) {
        await drop(`${base}/stock-floors/${floor.id}`);
      }
    },
    [CSRF, fixture, floorName, sweep] as const,
  );
}

/** A rectangle drawn for a place that exists: « Existant » first, since a new rectangle draws a new place by default. */
async function chooseExisting(page: Page): Promise<void> {
  await page.getByTestId('field-origin').click();
  await page.getByRole('option', { name: 'Un emplacement existant' }).click();
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
      // The map opens reading; arranging is a mode the URL keeps, so a reload lands back in it.
      await page.goto('/stock/plan');
      await expect(page.getByTestId('stock-map-title')).toBeVisible();
      await expect(page.getByTestId('stock-map-mode-read')).toHaveAttribute('aria-pressed', 'true');
      await expect(page.getByTestId('stock-shape-rack')).toHaveCount(0);
      await page.getByTestId('stock-map-mode-arrange').click();
      await expect(page).toHaveURL(/\/stock\/plan\?mode=arrange$/);

      // A floor of the establishment, at a level no other floor of this run occupies.
      await page.getByTestId('stock-floor-add').click();
      await page.getByTestId('field-name').fill(floorName);
      await page.getByTestId('field-level').fill(String(fixture.level));
      await page.getByTestId('field-widthMetres').fill('30');
      await page.getByTestId('field-depthMetres').fill('20');
      await page.getByTestId('stock-floor-save').click();
      await expect(toast(page)).toContainText('Étage enregistré');

      await page.getByRole('button', { name: floorButton(floorName) }).click();

      // The palette, end to end: its sizes are settings the API resolves for this company, so what the button says
      // and what the form is posed at must be the same measurement. Abandoned, not saved — this case's rack is
      // drawn by the form below.
      const rackShape = page.getByTestId('stock-shape-rack');
      await expect(rackShape).toBeVisible();
      const offered = ((await rackShape.textContent()) ?? '').match(/([\d.,]+)\s*×\s*([\d.,]+)/);
      if (offered === null) throw new Error('the palette does not say what size it poses');
      await rackShape.click();
      await expect(page.getByTestId('stock-drawing-form')).toBeVisible();
      expect(
        [
          comma(await page.getByTestId('field-width').inputValue()),
          comma(await page.getByTestId('field-depth').inputValue()),
        ],
        'the form is posed at the size the palette offered',
      ).toEqual([comma(offered[1] ?? ''), comma(offered[2] ?? '')]);

      // A rack posed from the palette is a NEW rack, created with its rectangle; « Annuler » takes both back, so
      // nothing of it is left behind.
      const posed = `${code}N`;
      await page.getByTestId('field-newLocationCode').fill(posed);
      await page.getByTestId('field-newLocationName').fill('Rayonnage posé');
      await page.getByTestId('stock-drawing-save').click();
      await expect(toast(page)).toContainText('Rectangle enregistré');
      await expect(page.getByTestId(`stock-drawing-${posed}`)).toBeVisible();
      await toast(page).getByRole('button', { name: 'Annuler' }).click();
      await expect(page.getByTestId(`stock-drawing-${posed}`)).toHaveCount(0);
      const codesLeft = await page.evaluate(async (companyId) => {
        const answer = await fetch(`/api/companies/${companyId}/stock-locations`);
        if (!answer.ok) throw new Error(`the locations answered ${answer.status}`);
        return ((await answer.json()) as { code: string }[]).map((location) => location.code);
      }, fixture.companyId);
      expect(codesLeft, 'the place made with the rectangle is gone with it').not.toContain(posed);

      // The rack, drawn by the form rather than by dragging: the form is the way in, on every window.
      await page.getByTestId('stock-map-trace').click();
      await page.getByTestId('stock-map-trace-type').click();
      await chooseExisting(page);
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
      // As a French screen writes a size.
      await expect(drawn).toContainText('3,9 × 0,6 m');
      await expect(page.locator('[data-testid^="stock-drawing-rect-"]')).toHaveAttribute(
        'x',
        '2.5',
      );

      // Moved, not drawn a second time: a rack is in one place, so the plan still carries one rectangle.
      await drawn.click();
      await page.getByTestId('stock-drawing-edit').click();
      await page.getByTestId('field-x').fill('5');
      await page.getByTestId('stock-drawing-save').click();
      await expect(toast(page)).toContainText('Rectangle enregistré');
      await expect(page.locator('[data-testid^="stock-drawing-rect-"]')).toHaveCount(1);
      await expect(page.locator('[data-testid^="stock-drawing-rect-"]')).toHaveAttribute('x', '5');

      // Dragged, not typed. The exact metre a pointer lands on depends on the window, so this asserts what the
      // rule promises and not a number: it moved, and it came to rest on the quarter-metre grid.
      const rect = page.locator('[data-testid^="stock-drawing-rect-"]').first();

      // The toast of the save above is an overlay, and `mouse.down()` runs NO actionability check — it presses
      // whatever is topmost at those coordinates and says nothing. `hover()` does check, so a covered rectangle
      // fails here naming what intercepted it, instead of silently pressing the toast and leaving the drag dead.
      await expect(toast(page)).toBeHidden({ timeout: 15_000 });
      await rect.scrollIntoViewIfNeeded();
      await rect.hover();
      const box = await rect.boundingBox();
      if (box === null) throw new Error('the rectangle is not laid out');
      await page.mouse.down();
      await page.mouse.move(box.x + box.width / 2 + 120, box.y + box.height / 2, { steps: 12 });
      await page.mouse.up();

      await expect(page.getByTestId('stock-drawing-unsaved')).toBeVisible();

      // Both halves are read and reported together: a failure that prints only "not 5" cannot say whether the form
      // never took the drag or the plan never drew it, which cost a CI round to work out once already.
      const after = {
        form: await page.getByTestId('field-x').inputValue(),
        plan: await rect.getAttribute('x'),
      };
      expect(
        after,
        'a drag of 120 px must move the rectangle off 5 m in the form AND on the plan',
      ).not.toEqual({ form: '5', plan: '5' });
      const dragged = comma(after.form);
      expect(Math.round(dragged * 4)).toBeCloseTo(dragged * 4, 6);

      await page.getByTestId('stock-drawing-save').click();
      await expect(toast(page)).toContainText('Rectangle enregistré');

      // It survives the round trip: what the drag wrote is what the API kept.
      await page.reload();
      await page.getByRole('button', { name: floorButton(floorName) }).click();
      await expect(rect).toHaveAttribute('x', String(dragged));

      // Moved by the keyboard, as a drag would: chosen, then an arrow writes a quarter metre into its form, unsaved
      // until Enregistrer, and abandoned here so the rectangle stays where the drag left it.
      await page.getByTestId(`stock-drawing-${code}`).click();

      // What a pointer lands on at a handle is its grip, at least 24 px across, not the small dot drawn under it.
      const grip = page.locator('[data-testid^="stock-drawing-grip-"]').first();
      await grip.scrollIntoViewIfNeeded();
      const held = await grip.boundingBox();
      if (held === null) throw new Error('the handle has no grip laid out');
      expect(Math.round(held.width)).toBeGreaterThanOrEqual(24);
      // Named in full when it misses, so a failure says WHAT took the pointer instead of the grip.
      const landed = await page.evaluate(
        ({ x, y }) => {
          const hit = document.elementFromPoint(x, y);
          if (hit === null) return `nothing at ${x},${y} in ${innerWidth}x${innerHeight}`;

          return (
            hit.getAttribute('data-testid') ?? `${hit.tagName}.${hit.getAttribute('class') ?? ''}`
          );
        },
        { x: held.x + held.width / 2, y: held.y + held.height / 2 },
      );
      expect(landed).toMatch(/^stock-drawing-grip-/);

      await page.getByTestId('stock-map-board').focus();
      await page.keyboard.press('ArrowRight');
      await expect(page.getByTestId('stock-drawing-unsaved')).toBeVisible();
      expect(comma(await page.getByTestId('field-x').inputValue())).toBeCloseTo(dragged + 0.25, 6);
      await page.getByTestId('stock-drawing-cancel').click();
      await expect(rect).toHaveAttribute('x', String(dragged));

      // Erased: the rectangle goes, the rack stays a rack — so « Annuler » draws it again where it stood.
      await page.getByTestId(`stock-drawing-${code}`).click();
      await page.getByTestId('stock-drawing-erase').click();
      await expect(toast(page)).toContainText('Rectangle effacé');
      await expect(page.getByTestId('stock-map-empty')).toBeVisible();
      await toast(page).getByRole('button', { name: 'Annuler' }).click();
      await expect(toast(page)).toContainText('Modification du plan annulée');
      await expect(rect).toHaveAttribute('x', String(dragged));

      await page.getByTestId(`stock-drawing-${code}`).click();
      await page.getByTestId('stock-drawing-erase').click();
      await expect(page.getByTestId('stock-map-empty')).toBeVisible();

      // Traced, not typed: the floor is bare now, so any part of the sheet is floor to draw on. It is armed first —
      // the sheet only becomes a drawing surface once the tool says so — and the box is abandoned rather than
      // saved, so the run leaves no rectangle and no location behind it.
      await expect(toast(page)).toBeHidden({ timeout: 15_000 });
      await page.getByTestId('stock-map-trace').click();
      await expect(page.getByTestId('stock-map-trace')).toHaveAttribute('aria-pressed', 'true');
      const sheet = page.getByTestId('stock-map-svg');
      await sheet.scrollIntoViewIfNeeded();
      await sheet.hover();
      const area = await sheet.boundingBox();
      if (area === null) throw new Error('the plan is not laid out');

      // Traced from the MIDDLE outwards. An SVG meets its box, so a wide sheet letterboxes the floor and a press
      // near the left edge lands in the band beside it — where both corners clamp to the floor's own corner and
      // the box comes out one grid step wide. That would pass every check below while proving nothing.
      const middle = { x: area.x + area.width / 2, y: area.y + area.height / 2 };
      await page.mouse.move(middle.x - 80, middle.y - 60);
      await page.mouse.down();
      await page.mouse.move(middle.x + 80, middle.y + 40, { steps: 12 });
      await page.mouse.up();

      await expect(page.getByTestId('stock-drawing-form')).toBeVisible();
      const traced = {
        width: comma(await page.getByTestId('field-width').inputValue()),
        depth: comma(await page.getByTestId('field-depth').inputValue()),
      };
      // Metres, not merely more than nothing: 160 px across the middle of the sheet can only come out one grid step
      // wide if the pointer never reached the floor's metres at all, which is the failure worth catching here.
      expect(traced.width, 'a box traced across 160 px is metres wide').toBeGreaterThanOrEqual(1);
      expect(traced.depth, 'and 100 px deep is not nothing either').toBeGreaterThanOrEqual(0.5);
      expect(Math.round(traced.width * 4)).toBeCloseTo(traced.width * 4, 6);
      expect(Math.round(traced.depth * 4)).toBeCloseTo(traced.depth * 4, 6);
      // One box per arming, so the sheet is something a finger can scroll past again. The traced box's form stands
      // where the tools were (finding B), so the sheet says it first, then the tool once it is back.
      await expect(sheet).not.toHaveClass(/cursor-crosshair/);
      await page.getByTestId('stock-drawing-cancel').click();
      await expect(page.getByTestId('stock-map-trace')).toHaveAttribute('aria-pressed', 'false');

      await page.goto('/stock/locations');
      await expect(page.getByTestId(`stock-location-${code}`)).toBeVisible();
    } finally {
      // A timed-out case closes its page, and cleaning a closed page throws over the failure that caused it —
      // which reads as a cleanup bug and hides the real one. Measured: it cost a whole CI round to see through.
      // The rack posed from the palette, should « Annuler » have failed before it took the place back.
      if (!page.isClosed()) await clean(page, fixture, floorName, `${code}N`);
    }
  });

  /**
   * Repeating a rack down an aisle, through the real stack. What this case is for is the one thing no unit test can
   * check: that the dotted preview the person accepts and the rectangles the API creates are the SAME rectangles.
   * The screen computes the preview itself, in JavaScript, and the API computes the copies in bcmath — two pieces
   * of arithmetic that must agree exactly or a person accepts one plan and gets another.
   *
   * The source rack carries the run's own number, so the copies' codes are the run's too and a second run does not
   * meet its own leftovers as a taken code.
   */
  test('repeats a rack down the aisle, creating the locations as well as the rectangles', async ({
    page,
  }) => {
    const stamp = Date.now().toString().slice(-8);
    // `X` between the stamp and the counter: without it the stem would be `REP` and the number the whole
    // eight-digit stamp, so the sweep below would reach every past run's rows and an increment could carry.
    const stem = `REP${stamp}X`;
    const code = `${stem}1`;
    const floorName = `Allée ${stamp}`;

    await signIn(page);
    await inACompany(page, CSRF);
    const fixture = await prepare(page, code);

    try {
      await page.goto('/stock/plan?mode=arrange');
      await page.getByTestId('stock-floor-add').click();
      await page.getByTestId('field-name').fill(floorName);
      await page.getByTestId('field-level').fill(String(fixture.level));
      await page.getByTestId('field-widthMetres').fill('30');
      await page.getByTestId('field-depthMetres').fill('20');
      await page.getByTestId('stock-floor-save').click();
      await expect(toast(page)).toContainText('Étage enregistré');
      await page.getByRole('button', { name: floorButton(floorName) }).click();

      await page.getByTestId('stock-map-trace').click();
      await page.getByTestId('stock-map-trace-type').click();
      await chooseExisting(page);
      await page.getByTestId('field-locationId').click();
      await page.getByRole('option', { name: new RegExp(code) }).click();
      await page.getByTestId('field-x').fill('2,5');
      await page.getByTestId('field-y').fill('4');
      await page.getByTestId('field-width').fill('3,9');
      await page.getByTestId('field-depth').fill('0,6');
      await page.getByTestId('field-height').fill('2,1');
      await page.getByTestId('stock-drawing-save').click();
      await expect(toast(page)).toContainText('Rectangle enregistré');

      await page.getByTestId(`stock-drawing-${code}`).click();
      await page.getByTestId('stock-drawing-repeat').click();
      await expect(page.getByTestId('stock-repeat-panel')).toBeVisible();

      // Opened on the next code and this company's own aisle width, both of which the person may change.
      await expect(page.getByTestId('stock-repeat-code')).toHaveValue(`${stem}2`);
      await page.getByTestId('stock-repeat-count').fill('3');
      await page.getByTestId('stock-repeat-spacing').fill('0,6');

      // What will be created, said before it is created — these are stock locations and not only shapes.
      await expect(page.getByTestId('stock-repeat-codes')).toHaveText(
        `${stem}2, ${stem}3, ${stem}4`,
      );

      // Refused HERE and not by the API: three metres of free floor between each copy, going up from y = 4, puts
      // the second one past the floor's own edge. The canvas asks for exactly this — "refusée avant, pas après" —
      // so the button is disabled and the reason is on the screen before anything is sent.
      await page.getByTestId('stock-repeat-way-up').click();
      await page.getByTestId('stock-repeat-spacing').fill('3');
      await expect(page.getByTestId('stock-repeat-save')).toBeDisabled();
      await expect(page.getByTestId('stock-repeat-summary')).toContainText('sol');

      await page.getByTestId('stock-repeat-way-down').click();
      await page.getByTestId('stock-repeat-spacing').fill('0,6');
      await expect(page.getByTestId('stock-repeat-save')).toBeEnabled();

      // The preview the person is about to accept, read straight off the plan as it now stands.
      const previewed = await page
        .locator('[data-testid^="stock-repeat-preview-"]')
        .evaluateAll((nodes) =>
          nodes.map((node) => ({ x: node.getAttribute('x'), y: node.getAttribute('y') })),
        );
      expect(previewed).toHaveLength(3);

      await page.getByTestId('stock-repeat-save').click();
      await expect(toast(page)).toContainText('Rayonnages créés');

      // The three copies are on the plan's list, each under its own code.
      for (const at of [2, 3, 4]) {
        await expect(page.getByTestId(`stock-drawing-${stem}${at}`)).toBeVisible();
      }

      // Read back off the plan itself, by the rectangles' own metres, and compared with the preview.
      await page.reload();
      await page.getByRole('button', { name: floorButton(floorName) }).click();
      // `evaluateAll` does not wait, so the count is asserted first: the source rectangle and the three copies.
      await expect(page.locator('[data-testid^="stock-drawing-rect-"]')).toHaveCount(4);
      const drawnNow = await page
        .locator('[data-testid^="stock-drawing-rect-"]')
        .evaluateAll((nodes) =>
          nodes.map((node) => ({ x: node.getAttribute('x'), y: node.getAttribute('y') })),
        );
      for (const copy of previewed) {
        expect(
          drawnNow,
          'every rectangle the preview showed was created exactly where it was shown',
        ).toContainEqual(copy);
      }

      // Several chosen at once (slice 7c): Shift-click adds one, a box on bare floor takes every one it touches, and
      // Échap lets the whole group go. Through the real pointer events, which a spec only imitates.
      const chosen = page.locator('[data-testid^="stock-drawing-rect-"].twes-map-chosen');
      await expect(toast(page)).toBeHidden({ timeout: 15_000 });
      await page.getByTestId(`stock-drawing-rect-${stem}2`).click();
      await page.getByTestId(`stock-drawing-rect-${stem}3`).click({ modifiers: ['Shift'] });
      await expect(chosen).toHaveCount(2);
      await expect(page.getByTestId('stock-map-chosen')).toContainText('2 rectangles choisis');
      await expect(page.locator('[data-testid^="stock-drawing-grip-"]')).toHaveCount(0);

      const sheet = page.getByTestId('stock-map-svg');
      await sheet.hover({ position: { x: 3, y: 3 } });
      const area = await sheet.boundingBox();
      if (area === null) throw new Error('the plan is not laid out');
      await page.mouse.down();
      await page.mouse.move(area.x + area.width - 3, area.y + area.height - 3, { steps: 12 });
      await expect(page.getByTestId('stock-map-selection-box')).toBeVisible();
      await page.mouse.up();
      await expect(chosen).toHaveCount(4);
      await expect(page.getByTestId('stock-map-chosen')).toContainText('4 rectangles choisis');

      // Acted on together (slice 7c): undrawn in one request, drawn back for the same places by one « Annuler ».
      const rects = page.locator('[data-testid^="stock-drawing-rect-"]');
      await page.getByTestId('stock-map-group-erase').click();
      await expect(toast(page)).toContainText('4 rectangles effacés');
      await expect(rects).toHaveCount(0);
      await toast(page).getByRole('button', { name: 'Annuler' }).click();
      await expect(rects).toHaveCount(4);
      const backAgain = await rects.evaluateAll((nodes) =>
        nodes.map((node) => ({ x: node.getAttribute('x'), y: node.getAttribute('y') })),
      );
      for (const copy of previewed) expect(backAgain).toContainEqual(copy);
      await expect(toast(page)).toBeHidden({ timeout: 15_000 });

      // And moved together, by one step for all, saved in one request.
      await sheet.hover({ position: { x: 3, y: 3 } });
      await page.mouse.down();
      await page.mouse.move(area.x + area.width - 3, area.y + area.height - 3, { steps: 12 });
      await page.mouse.up();
      await expect(chosen).toHaveCount(4);
      await page.getByTestId('stock-map-board').focus();
      await page.keyboard.press('ArrowRight');
      await page.getByTestId('stock-map-group-save').click();
      await expect(toast(page)).toContainText('4 rectangles déplacés');
      await expect(page.getByTestId(`stock-drawing-rect-${stem}2`)).toHaveAttribute('x', '2.75');

      await page.keyboard.press('Escape');
      await expect(chosen).toHaveCount(0);
      await expect(page.getByTestId('stock-map-chosen')).toHaveCount(0);

      // And each copy is a stock location of its own, ready for goods rather than a picture of one.
      await page.goto('/stock/locations');
      for (const at of [2, 3, 4]) {
        await expect(page.getByTestId(`stock-location-${stem}${at}`)).toBeVisible();
      }
    } finally {
      if (!page.isClosed()) await clean(page, fixture, floorName, stem);
    }
  });

  /**
   * The Structure board through the real stack: a door posed with its own tool at the measurements this company
   * builds at, read back off the plan, then locked and hidden, then taken away. Nothing posed here is a stock
   * location, which the case proves by looking: the door appears on no list of what is drawn and on no location
   * list. The floor is the run's own and is removed at the end, which takes any structure left on it with it.
   */
  test('poses a door on the building, reads it off the plan, locks and hides it, then erases it', async ({
    page,
  }) => {
    const stamp = Date.now().toString().slice(-8);
    const code = `BLD${stamp}`;
    const floorName = `Bâti ${stamp}`;

    await signIn(page);
    await inACompany(page, CSRF);
    const fixture = await prepare(page, code);

    try {
      await page.goto('/stock/plan?mode=arrange');
      await page.getByTestId('stock-floor-add').click();
      await page.getByTestId('field-name').fill(floorName);
      await page.getByTestId('field-level').fill(String(fixture.level));
      await page.getByTestId('field-widthMetres').fill('30');
      await page.getByTestId('field-depthMetres').fill('20');
      await page.getByTestId('stock-floor-save').click();
      await expect(toast(page)).toContainText('Étage enregistré');
      await page.getByRole('button', { name: floorButton(floorName) }).click();

      // The tool says what it poses, and the form opens at exactly that: both come from `venue.structure.*`, so a
      // tool posing a constant instead of the company's own door would disagree with its own label here.
      const doorTool = page.getByTestId('stock-structure-tool-door');
      await expect(doorTool).toBeVisible();
      const offered = ((await doorTool.textContent()) ?? '').match(/([\d.,]+)\s*×\s*([\d.,]+)/);
      if (offered === null) throw new Error('the door tool does not say what size it poses');
      await doorTool.click();
      expect(
        [
          comma(await page.getByTestId('field-width').inputValue()),
          comma(await page.getByTestId('field-depth').inputValue()),
        ],
        'the form is posed at the size the tool offered',
      ).toEqual([comma(offered[1] ?? ''), comma(offered[2] ?? '')]);

      await page.getByTestId('field-x').fill('1');
      await page.getByTestId('field-y').fill('0');
      await page.getByTestId('stock-structure-save').click();
      await expect(toast(page)).toContainText('Élément de structure enregistré');

      // Read back off the plan by its own metres, and counted on its own layer.
      const door = page.locator('[data-testid="stock-structure-door"]');
      await expect(door).toHaveCount(1);
      await expect(door).toHaveAttribute('x', '1');
      await expect(page.getByTestId('stock-layer-count-structure')).toHaveText('1');

      // It is none of the stock: no rectangle is drawn, and the door is on no list of places.
      await expect(page.locator('[data-testid^="stock-drawing-rect-"]')).toHaveCount(0);
      await expect(page.getByTestId('stock-map-empty')).toBeVisible();

      // Locked keeps it drawn and stops it answering the pointer; hidden takes it off the plan.
      await page.getByTestId('stock-layer-locked-structure').click();
      await expect(page.getByTestId('stock-structure-layer')).toHaveClass(/pointer-events-none/);
      await expect(door).toHaveCount(1);
      await page.getByTestId('stock-layer-shown-structure').click();
      await expect(page.getByTestId('stock-structure-layer')).toHaveCount(0);

      // Shown again so the form can be opened from it, then taken away.
      await page.getByTestId('stock-layer-shown-structure').click();
      await expect(toast(page)).toBeHidden({ timeout: 15_000 });
      await page.getByTestId('stock-layer-locked-structure').click();
      await door.click();
      await page.getByTestId('stock-structure-erase').click();
      await expect(toast(page)).toContainText('Élément de structure supprimé');
      await expect(door).toHaveCount(0);
      await expect(page.getByTestId('stock-layer-count-structure')).toHaveText('0');
    } finally {
      if (!page.isClosed()) await clean(page, fixture, floorName);
    }
  });

  /**
   * What the plan writes on a rectangle, through the real stack (docs/SPEC.md § 7, 2026-09-22). A store arrives
   * with its building already numbered, or already named, or moving from one to the other.
   *
   * It screenshots each of the three, because a label is a rendered thing: an assertion on `textContent` passes on
   * a label drawn outside its own rectangle, behind another, or in a colour nobody can read.
   */
  test('writes the code, the name or both on the plan, and remembers which', async ({ page }) => {
    const stamp = Date.now().toString().slice(-8);
    const code = `LBL${stamp}`;
    const floorName = `Libellé ${stamp}`;

    await signIn(page);
    await inACompany(page, CSRF);
    const fixture = await prepare(page, code);

    try {
      await page.goto('/stock/plan?mode=arrange');
      await page.getByTestId('stock-floor-add').click();
      await page.getByTestId('field-name').fill(floorName);
      await page.getByTestId('field-level').fill(String(fixture.level));
      await page.getByTestId('field-widthMetres').fill('30');
      await page.getByTestId('field-depthMetres').fill('20');
      await page.getByTestId('stock-floor-save').click();
      await expect(toast(page)).toContainText('Étage enregistré');
      await page.getByRole('button', { name: floorButton(floorName) }).click();

      // One rack, and one wall to prove the building keeps its name under every choice.
      await page.getByTestId('stock-map-trace').click();
      await page.getByTestId('stock-map-trace-type').click();
      await chooseExisting(page);
      await page.getByTestId('field-locationId').click();
      await page.getByRole('option', { name: new RegExp(code) }).click();
      await page.getByTestId('field-x').fill('1');
      await page.getByTestId('field-y').fill('2');
      await page.getByTestId('field-width').fill('4');
      await page.getByTestId('field-depth').fill('1');
      await page.getByTestId('stock-drawing-save').click();
      await expect(toast(page)).toContainText('Rectangle enregistré');

      await page.getByTestId('stock-structure-tool-wall').click();
      await page.getByTestId('field-name').fill('Mur nord');
      await page.getByTestId('field-x').fill('0');
      await page.getByTestId('field-y').fill('0');
      await page.getByTestId('stock-structure-save').click();
      await expect(toast(page)).toContainText('Élément de structure enregistré');
      await expect(toast(page)).toBeHidden({ timeout: 15_000 });

      const label = page.locator('[data-testid^="stock-drawing-label-"]');
      const wall = page.locator('[data-testid^="stock-structure-name-"]');

      // Chosen, never assumed. This is a PREFERENCE persisted on the operator account, which every scenario and
      // every run of this suite shares: a run that fails before its own reset leaves it set, and the next run then
      // opens on whatever that was. An earlier draft asserted the declared default here and failed on its second
      // run for that reason alone. What the default is belongs to the unit spec, which owns its own storage.
      await page.getByTestId('stock-map-label-code').click();
      await expect(label).toHaveText(code);
      await expect(wall).toHaveText('Mur nord');

      // Both halves are longer than a 4 m rack can carry at this type size, so the label is CUT — found by looking
      // at the rendered plan, where it ran 6,1 m across a 3,9 m rack and onto the empty floor beside it.
      await page.getByTestId('stock-map-label-both').click();
      // `toHaveText` normalizes whitespace for a string and NOT for a pattern, and an SVG text node carries the
      // template's own indentation, so the pattern allows it rather than pretending it is not there.
      await expect(label).toHaveText(new RegExp(`^\\s*${code} · Rayonn.*…\\s*$`));
      // Nothing is hidden by cutting it: the whole label rides on the rectangle, which is what a person points at.
      await expect(page.locator('[data-testid^="stock-drawing-rect-"] title')).toHaveText(
        `${code} · Rayonnage ${code}`,
      );
      await expect(wall).toHaveText('Mur nord', { timeout: 5_000 });

      await page.getByTestId('stock-map-label-name').click();
      await expect(label).toHaveText(`Rayonnage ${code}`);

      // A preference, not a moment: the next visit opens on the numbering this store actually reads.
      await page.reload();
      await page.getByRole('button', { name: floorButton(floorName) }).click();
      await expect(label).toHaveText(`Rayonnage ${code}`);
      await expect(page.getByTestId('stock-map-label-name')).toHaveAttribute(
        'aria-pressed',
        'true',
      );

      // Put back, so the next scenario in this shared database opens on codes as it expects to.
      await page.getByTestId('stock-map-label-code').click();
      await expect(label).toHaveText(code);
    } finally {
      if (!page.isClosed()) await clean(page, fixture, floorName);
    }
  });
  /**
   * The volume through the real stack: three.js fetched on the first press, the floor drawn by a real browser, the
   * choice kept on the account. What is asserted is what a person reads; a frame's time is load, not evidence.
   */
  test('looks at a floor in volume, keeps the choice, and arranges on the plan', async ({
    page,
  }) => {
    const stamp = Date.now().toString().slice(-8);
    const code = `VOL${stamp}`;
    const floorName = `Volume ${stamp}`;

    await signIn(page);
    await inACompany(page, CSRF);
    const fixture = await prepare(page, code);

    try {
      await page.goto('/stock/plan?mode=arrange');
      await page.getByTestId('stock-floor-add').click();
      await page.getByTestId('field-name').fill(floorName);
      await page.getByTestId('field-level').fill(String(fixture.level));
      await page.getByTestId('field-widthMetres').fill('20');
      await page.getByTestId('field-depthMetres').fill('10');
      await page.getByTestId('stock-floor-save').click();
      await expect(toast(page)).toContainText('Étage enregistré');
      await page.getByRole('button', { name: floorButton(floorName) }).click();

      await page.getByTestId('stock-map-trace').click();
      await page.getByTestId('stock-map-trace-type').click();
      await chooseExisting(page);
      await page.getByTestId('field-locationId').click();
      await page.getByRole('option', { name: new RegExp(code) }).click();
      await page.getByTestId('field-x').fill('2');
      await page.getByTestId('field-y').fill('2');
      await page.getByTestId('field-width').fill('6');
      await page.getByTestId('field-depth').fill('1');
      await page.getByTestId('stock-drawing-save').click();
      await expect(toast(page)).toContainText('Rectangle enregistré');

      // Arranging is done on the plan: the volume is named there, and waits for Consulter.
      await expect(page.getByTestId('stock-map-view-volume')).toHaveAttribute(
        'aria-disabled',
        'true',
      );

      await page.getByTestId('stock-map-mode-read').click();
      await page.getByRole('button', { name: floorButton(floorName) }).click();
      await page.getByTestId('stock-map-view-volume').click();
      await expect(page.getByTestId('stock-map-view-volume')).toHaveAttribute(
        'aria-pressed',
        'true',
      );
      await expect(page.getByTestId('stock-map-board')).toHaveCount(0);
      // French holds the colon with a no-break space, which a pattern's \\s reads and a typed space would not.
      await expect(page.getByTestId('stock-volume-summary')).toContainText(
        new RegExp(`${floorName}\\s:\\s1 rayonnage`),
      );
      // Drawn where the browser can, said where it cannot: either way the person is told, never shown a blank.
      const drawnOrSaid = page
        .getByTestId('stock-volume-turn-right')
        .or(page.getByTestId('stock-volume-missing'));
      await expect(drawnOrSaid).toBeVisible();
      if (await page.getByTestId('stock-volume-turn-right').isVisible()) {
        await page.getByTestId('stock-volume-turn-right').click();
        await page.getByTestId('stock-volume-top').click();
        await expect(page.getByTestId('stock-volume-canvas')).toBeVisible();
      }
      await page.screenshot({ path: test.info().outputPath('volume.png') });

      // A preference, not a moment: the next visit opens on the volume.
      await page.reload();
      await page.getByRole('button', { name: floorButton(floorName) }).click();
      await expect(page.getByTestId('stock-map-view-volume')).toHaveAttribute(
        'aria-pressed',
        'true',
      );
      await expect(page.getByTestId('stock-volume-summary')).toContainText(floorName);

      // Aménager draws on the plan whatever was chosen.
      await page.getByTestId('stock-map-mode-arrange').click();
      await expect(page.getByTestId('stock-map-board')).toBeVisible();
      await expect(page.getByTestId('stock-volume')).toHaveCount(0);
    } finally {
      if (!page.isClosed()) {
        // Forgotten, so every other scenario on this shared account opens on the plan as it expects to.
        const status = await page.evaluate(async (csrf) => {
          const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
          const response = await fetch(
            `/api/companies/${me.company.id}/settings/presentation.stock-map-view?level=user`,
            { method: 'DELETE', headers: { 'csrf-token': csrf } },
          );
          return response.status;
        }, CSRF);
        expect([204, 404]).toContain(status);
        await clean(page, fixture, floorName);
      }
    }
  });

  /**
   * The map over the whole window (docs/SPEC.md § 7, 2026-10-09 23:19): F lays it over the shell, the chosen place's
   * panel beside it, and Échap gives the page back with the focus where it was. Nothing is moved, so nothing leaks.
   */
  test('fills the window with the map on F, and gives the page back on Échap', async ({ page }) => {
    const stamp = Date.now().toString().slice(-8);
    const code = `FUL${stamp}`;
    const floorName = `Plein ${stamp}`;

    await signIn(page);
    await inACompany(page, CSRF);
    const fixture = await prepare(page, code);

    try {
      await page.goto('/stock/plan?mode=arrange');
      await page.getByTestId('stock-floor-add').click();
      await page.getByTestId('field-name').fill(floorName);
      await page.getByTestId('field-level').fill(String(fixture.level));
      await page.getByTestId('field-widthMetres').fill('20');
      await page.getByTestId('field-depthMetres').fill('10');
      await page.getByTestId('stock-floor-save').click();
      await expect(toast(page)).toContainText('Étage enregistré');
      await page.getByRole('button', { name: floorButton(floorName) }).click();

      await page.getByTestId('stock-map-trace').click();
      await page.getByTestId('stock-map-trace-type').click();
      await chooseExisting(page);
      await page.getByTestId('field-locationId').click();
      await page.getByRole('option', { name: new RegExp(code) }).click();
      await page.getByTestId('field-x').fill('2');
      await page.getByTestId('field-y').fill('2');
      await page.getByTestId('field-width').fill('6');
      await page.getByTestId('field-depth').fill('1');
      await page.getByTestId('stock-drawing-save').click();
      await expect(toast(page)).toContainText('Rectangle enregistré');

      await page.getByTestId('stock-map-mode-read').click();
      await page.getByRole('button', { name: floorButton(floorName) }).click();
      await page.getByTestId(`stock-drawing-${code}`).click();
      await expect(page.getByTestId('stock-map-selection')).toContainText(code);

      // A key of the screen's, held one scan gap by the shell before it runs.
      await page.getByTestId('stock-map-board').focus();
      await page.keyboard.press('f');
      const layer = page.getByTestId('stock-map-layer');
      await expect(layer).toHaveClass(/twes-map-full/);
      await expect(page.getByTestId('stock-map-full-leave')).toBeVisible();

      // Over the rail and the bar, not under them: what lies where the rail is drawn is the map's. The rail is inert
      // meanwhile, and an inert element is passed over by `elementFromPoint` wherever it is painted, so it is asked
      // with the rail made reachable for that one question: the answer is then about which of the two is on top.
      const viewport = page.viewportSize()!;
      await expect(page.locator('mat-sidenav')).toHaveAttribute('inert', '');
      const covered = await page.evaluate(
        ([x, y]) => {
          const rail = document.querySelector('mat-sidenav')!;
          rail.removeAttribute('inert');
          const found = document.elementFromPoint(x, y);
          rail.setAttribute('inert', '');
          const map = document.querySelector('[data-testid="stock-map-layer"]');
          return map !== null && found !== null && map.contains(found);
        },
        [40, Math.round(viewport.height / 2)] as const,
      );
      expect(covered).toBe(true);
      const board = (await page.getByTestId('stock-map-board').boundingBox())!;
      expect(board.width).toBeGreaterThan(viewport.width * 0.5);
      expect(board.height).toBeGreaterThan(viewport.height * 0.6);
      // The bar holds on a laptop and on a window between a phone and a wide screen: every control inside the
      // window, none over another, each label on one line. A long floor name is what pushed it out first.
      expect(await barMisfits(page)).toEqual([]);
      await expect(page.getByTestId('stock-map-selection')).toContainText(code);
      await page.screenshot({ path: test.info().outputPath('full-screen.png') });
      expect(await wcagViolations(page)).toEqual([]);

      await page.keyboard.press('Escape');
      await expect(layer).not.toHaveClass(/twes-map-full/);
      await expect(page.getByTestId('stock-map-full')).toBeFocused();
      await expect(page.getByTestId('stock-map-selection')).toContainText(code);

      // Between a phone and a wide screen too; the browser refuses a resize while it holds its own full screen.
      await page.setViewportSize({ width: 900, height: 800 });
      await page.getByTestId('stock-map-board').focus();
      await page.keyboard.press('f');
      await expect(layer).toHaveClass(/twes-map-medium/);
      expect(await barMisfits(page)).toEqual([]);
      await page.keyboard.press('Escape');
      await expect(layer).not.toHaveClass(/twes-map-full/);
      await page.setViewportSize(viewport);
    } finally {
      if (!page.isClosed()) await clean(page, fixture, floorName);
    }
  });

  /**
   * What a place holds, read on the plan and from « Emplacements ». The run's rack holds nothing, so no count is
   * written on it: a place that received goods could not be taken away afterwards, and would leak one per run into the
   * shared company. The counts themselves are certified by the API's functional test and the board's unit spec.
   */
  test('says what a place holds, on the plan and from the locations list', async ({ page }) => {
    const stamp = Date.now().toString().slice(-8);
    const code = `HLD${stamp}`;
    const floorName = `Contenu ${stamp}`;

    await signIn(page);
    await inACompany(page, CSRF);
    const fixture = await prepare(page, code);

    try {
      await page.goto('/stock/plan?mode=arrange');
      await page.getByTestId('stock-floor-add').click();
      await page.getByTestId('field-name').fill(floorName);
      await page.getByTestId('field-level').fill(String(fixture.level));
      await page.getByTestId('field-widthMetres').fill('20');
      await page.getByTestId('field-depthMetres').fill('10');
      await page.getByTestId('stock-floor-save').click();
      await expect(toast(page)).toContainText('Étage enregistré');
      await page.getByRole('button', { name: floorButton(floorName) }).click();

      await page.getByTestId('stock-map-trace').click();
      await page.getByTestId('stock-map-trace-type').click();
      await chooseExisting(page);
      await page.getByTestId('field-locationId').click();
      await page.getByRole('option', { name: new RegExp(code) }).click();
      await page.getByTestId('field-x').fill('2');
      await page.getByTestId('field-y').fill('2');
      await page.getByTestId('field-width').fill('6');
      await page.getByTestId('field-depth').fill('1');
      await page.getByTestId('stock-drawing-save').click();
      await expect(toast(page)).toContainText('Rectangle enregistré');

      await page.getByTestId('stock-map-mode-read').click();
      await page.getByRole('button', { name: floorButton(floorName) }).click();
      // Reading, the plan says a place is pressed to be read, and writes no count on one holding nothing.
      await expect(page.getByTestId('stock-map-press-hint')).toBeVisible();
      await expect(page.getByTestId(`stock-map-count-${code}`)).toHaveCount(0);
      await page.getByTestId(`stock-drawing-${code}`).click();
      await expect(page.getByTestId('stock-contents-nothing')).toBeVisible();

      // The same answer from the list, for a place a person reaches without the plan.
      await page.goto('/stock/locations');
      // Filtered first: the shared company outgrows a page, and the run's rack would sort onto the next one.
      await page.getByTestId('list-filter').fill(code);
      await page.getByTestId(`row-action-contents-${fixture.locationId}`).click();
      await expect(page.getByTestId('place-contents-title')).toContainText(code);
      await expect(page.getByTestId('stock-contents-nothing')).toBeVisible();
      await page.getByTestId('place-contents-close').click();
      await expect(page.getByTestId('place-contents-title')).toHaveCount(0);
    } finally {
      if (!page.isClosed()) await clean(page, fixture, floorName);
    }
  });
});
