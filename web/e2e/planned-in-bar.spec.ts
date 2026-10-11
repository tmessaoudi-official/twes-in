// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test, type Page } from '@playwright/test';
import { inACompany, signIn } from './session';

// A page's « Bientôt » actions are dashed in its bar where they fit and in « ⋮ » where they would not: shown, they
// must never add a row to the header. Each width is judged by the header itself, with and without them, so the test
// holds whichever side of the line a width falls on, and says which side it found.
const CSRF = '0123456789abcdef0123456789abcdef';

interface Rows {
  shown: boolean;
  /** The header's rows (title and bar, then each row of the bar) with the « Bientôt » buttons in the bar. */
  withThem: number;
  /** The same header's rows with them out of the flow. */
  without: number;
}

/** Counts the distinct tops of the title, the bar's row and every button in it, as drawn now and with them out. */
function rows(page: Page, barId: string): Promise<Rows> {
  return page.evaluate((barId) => {
    const header = document.querySelector<HTMLElement>('.twes-document-header')!;
    const bar = header.querySelector<HTMLElement>(`[data-testid="${barId}"]`)!;
    const planned = [...bar.querySelectorAll<HTMLElement>('[data-planned]')];
    // Centres, not tops: the header and the bar centre their items, which are not all one height.
    const count = () => {
      const centres = [header.firstElementChild!, ...bar.children]
        .filter((each) => {
          const box = each.getBoundingClientRect();
          return box.width > 0 && getComputedStyle(each).position !== 'absolute';
        })
        .map((each) => {
          const box = each.getBoundingClientRect();
          return box.top + box.height / 2;
        })
        .sort((a, b) => a - b);
      return centres.filter((centre, index) => index === 0 || centre - centres[index - 1]! > 8)
        .length;
    };
    const shown =
      planned.length > 0 &&
      planned.every((each) => !each.classList.contains('twes-planned-measure'));
    const withThem = count();
    planned.forEach((each) => each.classList.add('twes-planned-measure'));
    const without = count();
    if (shown) planned.forEach((each) => each.classList.remove('twes-planned-measure'));
    return { shown, withThem, without };
  }, barId);
}

interface Bar {
  list: string;
  page: string;
  bar: string;
  more: string;
  module: string;
  inMenu: string;
}

const BARS: readonly Bar[] = [
  // Any invoice still due: its bar holds the most, the next step among them.
  {
    list: '/invoices?status=issued,overdue',
    page: '/invoices/',
    bar: 'document-actions',
    more: 'document-more',
    module: 'mailing',
    inMenu: 'document-planned-mailing',
  },
  {
    list: '/vendors',
    page: '/vendors/',
    bar: 'record-bar',
    more: 'record-more',
    module: 'purchases',
    inMenu: 'record-planned-purchases',
  },
];

/** Opens the first record of a list on its own page: a row's link may open a sheet over the list instead. */
async function openFirst(page: Page, bar: Bar): Promise<void> {
  await page.goto(bar.list);
  const first = page.locator('a[data-testid^="list-link-"]').first();
  // The list's first page is read with the filter: on a loaded machine it outruns the default wait.
  await expect(first).toBeVisible({ timeout: 20_000 });
  const id = (await first.getAttribute('data-testid'))!.replace('list-link-', '');
  await page.goto(bar.page + id);
}

/** A vendor of this run's own: the shared company's earlier runs retired theirs, and a retired one is not listed. */
async function newVendor(page: Page, number: string): Promise<void> {
  await page.goto('/vendors/new');
  await page.getByTestId('field-number').fill(number);
  await page.getByTestId('field-name').fill(`Bientôt ${number}`);
  await page.getByTestId('record-save').click();
  await expect(page).toHaveURL(/\/vendors\/[0-9a-f-]{36}$/, { timeout: 20_000 });
}

/** Retires the run's vendor, as the vendors scenario does with its own. */
async function retire(page: Page, number: string): Promise<void> {
  await page.evaluate(
    async ([csrf, vendorNumber]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const base = `/api/companies/${me.company.id}`;
      const listed = (await (
        await fetch(`${base}/vendors?q=${encodeURIComponent(vendorNumber)}`)
      ).json()) as { member: { id: string; number: string }[] };
      const hit = listed.member.find((row) => row.number === vendorNumber);
      if (hit === undefined) return;
      const { id, ...fields } = (await (await fetch(`${base}/vendors/${hit.id}`)).json()) as {
        id: string;
      };
      const revised = await fetch(`${base}/vendors/${id}`, {
        method: 'PUT',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify({ ...fields, isActive: false }),
      });
      if (!revised.ok) throw new Error(`retiring ${vendorNumber} answered ${revised.status}`);
    },
    [CSRF, number] as const,
  );
}

test('a page bar shows what is coming dashed only where the header keeps its rows', async ({
  page,
}, testInfo) => {
  await signIn(page);
  await inACompany(page, CSRF);

  const vendor = `E2E-SOON-${Date.now().toString(36).toUpperCase()}`;
  const found: Record<string, Rows> = {};
  try {
    for (const bar of BARS) {
      if (bar.bar === 'record-bar') await newVendor(page, vendor);
      else await openFirst(page, bar);
      await expect(page.getByTestId(bar.bar)).toBeVisible();
      for (const width of [1440, 1280, 1024, 900]) {
        await page.setViewportSize({ width, height: 900 });
        // The bar measures again once the window has settled: two frames, then its answer.
        await page.evaluate(
          () => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done))),
        );
        const now = await rows(page, bar.bar);
        found[`${bar.bar} ${width}`] = now;
        await page.screenshot({ path: testInfo.outputPath(`${bar.bar}-${width}.png`) });
        if (now.shown) {
          expect(now.withThem, `${bar.bar} at ${width} px: shown, they added a row`).toBe(
            now.without,
          );
          await expect(page.getByTestId(`planned-action-${bar.module}`)).toBeVisible();
        } else {
          await page.getByTestId(bar.more).click();
          await expect(page.getByTestId(bar.inMenu)).toBeVisible();
          await page.keyboard.press('Escape');
        }
      }
    }
    testInfo.annotations.push({ type: 'rows', description: JSON.stringify(found) });
    // A vendor's bar is short: at 1440 px its one planned action has the room, so the shown side is exercised too.
    expect(found['record-bar 1440']!.shown).toBe(true);

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.getByTestId('planned-action-purchases').click();
    await expect(page).toHaveURL(/\/coming\/purchases$/);
  } finally {
    await retire(page, vendor);
  }
});
