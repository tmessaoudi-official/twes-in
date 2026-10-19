// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page } from '@playwright/test';

// Presentation choices are kept by the API since G3b, so one scenario's dark scheme or hidden column would reach
// the next scenario, and the next run, through the shared database. Each scenario that changes one starts by
// forgetting the signed-in person's own choices; company and role defaults are left alone.

// The SPA sends one random token per page load as a header; any value of the right shape is accepted, and the
// origin proof comes from the request being made by the page itself.
const CSRF = '0123456789abcdef0123456789abcdef';

export async function forgetPresentationChoices(page: Page): Promise<void> {
  const statuses = await page.evaluate(async (csrf) => {
    const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } | null };
    if (me.company === null) return [];
    const base = `/api/companies/${me.company.id}/settings`;
    const rows = (await (await fetch(`${base}?chain=presentation`)).json()) as {
      key: string;
      levels: { level: string }[];
    }[];
    const mine = rows.filter((row) => row.levels.some((level) => level.level === 'user'));
    return Promise.all(
      mine.map(
        async (row) =>
          (
            await fetch(`${base}/${encodeURIComponent(row.key)}?level=user`, {
              method: 'DELETE',
              headers: { 'csrf-token': csrf },
            })
          ).status,
      ),
    );
  }, CSRF);
  expect(statuses.every((status) => status === 204)).toBe(true);
  // What the page already shows was read before the choices were forgotten.
  await page.reload();
}

/**
 * The save of one presentation choice, awaited before a reload: the choice is a request of its own, and a reload sent
 * before its answer cancels it, so the next page opens on the choice before. Set it up before the click that makes
 * the choice; `holding` tells it from a save of the same key still on its way, by a word its body carries.
 */
export function choiceSaved(page: Page, key: string, holding?: string): Promise<void> {
  return page
    .waitForResponse(
      (response) =>
        response.request().method() === 'PUT' &&
        new URL(response.url()).pathname.endsWith(`/settings/${key}`) &&
        (holding === undefined || (response.request().postData() ?? '').includes(holding)),
    )
    .then((response) => expect(response.ok(), `saving ${key}`).toBe(true));
}
