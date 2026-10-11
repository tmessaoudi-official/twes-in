// SPDX-License-Identifier: AGPL-3.0-or-later
import { mkdirSync, writeFileSync } from 'node:fs';
import { type Page, expect, test } from '@playwright/test';
import { forgetPresentationChoices } from '../e2e/presentation';
import { signInWithCode } from '../e2e/session';
import { type Cue, chapters, holdFor, subtitles } from './captions';

// « Votre première facture », filmed: the guide is opened from Ctrl K as a person opens it, each step's narration is
// drawn in the page and held as long as it takes to read, and between the steps the work is done at a person's pace.
// Nothing is saved: the company's drafts are counted before and after, and the run fails if they differ.
const OUT = process.env['VIDEOS_DIR'] ?? '../var/claude/videos';
const COMPANY = process.env['RECORD_COMPANY'] ?? 'Carthage Conseil';
const LANGUAGE = 'fr';
const VIEWPORT = { width: 1280, height: 800 };

/** The narration under each step. A key is read from the screen, never written here: a person may have changed it. */
const NARRATION: readonly ((key: string) => string)[] = [
  () => 'Vos factures sont ici : cherchez-en une par son numéro ou son client.',
  (key) =>
    `Pour en créer une, ouvrez “Créer”, ou appuyez sur ${key} : la touche se change dans Préférences.`,
  () => 'Choisissez le client, ajoutez vos articles : les totaux suivent ce que vous tapez.',
  () => 'Enregistrez le brouillon, puis émettez-la : elle prend alors son numéro.',
];

test('« Votre première facture », filmed', async ({ page, browser, baseURL }) => {
  // Everything before the camera: the session, the company, the cookie notice, and the two pages compiled once, so
  // the video opens on the first step and not on a sign-in or a cold page.
  await signInWithCode(page);
  await workIn(page, COMPANY);
  await forgetPresentationChoices(page);
  await page.goto('/');
  await page
    .getByTestId('cookie-notice-close')
    .click({ timeout: 5_000 })
    .catch(() => undefined);
  for (const warm of ['/invoices', '/invoices/new']) {
    await page.goto(warm);
    await page.waitForLoadState('networkidle').catch(() => undefined);
  }
  const draftsBefore = await drafts(page);
  const storageState = await page.context().storageState();

  const out = `${OUT}/${LANGUAGE}`;
  mkdirSync(out, { recursive: true });
  const filming = await browser.newContext({
    baseURL,
    storageState,
    viewport: VIEWPORT,
    deviceScaleFactor: 1,
    locale: 'fr-FR',
    timezoneId: 'Africa/Tunis',
    recordVideo: { dir: out, size: VIEWPORT },
  });
  const began = Date.now();
  const at = () => Date.now() - began;
  const film = await filming.newPage();
  const cues: Cue[] = [];
  try {
    await film.goto('/');
    await expect(film.getByTestId('greeting')).toBeVisible();
    await film.waitForTimeout(1_200);

    await film.keyboard.press('Control+k');
    await film.getByTestId('command-input').pressSequentially('guide', { delay: 90 });
    await film
      .locator('[data-testid^="command-guide-"]')
      .filter({ hasText: /première facture/i })
      .click();

    for (let step = 0; step < NARRATION.length; step++) {
      await expect(film.getByTestId('tour-card')).toBeVisible();
      await expect(film.getByTestId('tour-title')).toBeFocused();
      const key =
        (
          await film.locator('[data-testid="create-open"]:visible kbd').first().textContent()
        )?.trim() ?? '';
      const line = NARRATION[step]!(key);
      const start = at();
      await caption(film, line);
      const shown = Date.now();
      if (step === 2) await draft(film);
      // The line stays its reading time, and longer when the work under it took longer.
      await film.waitForTimeout(Math.max(holdFor(line) - (Date.now() - shown), 1_200));
      cues.push({ start, end: at(), text: line });
      await film.getByTestId('tour-next').click();
    }
    await caption(film, null);
    await expect(film.getByTestId('tour-card')).toHaveCount(0);
    await film.waitForTimeout(1_500);
  } finally {
    await filming.close();
  }
  const video = film.video();
  if (video === null) throw new Error('the filming context recorded no video');
  await video.saveAs(`${out}/first-invoice.webm`);
  await video.delete();

  writeFileSync(`${out}/first-invoice.${LANGUAGE}.vtt`, subtitles(cues));
  writeFileSync(`${out}/first-invoice.chapters.vtt`, chapters(cues));
  writeFileSync(`${out}/first-invoice.cues.json`, JSON.stringify(cues, null, 2));

  expect(await drafts(page), 'the recording saved no draft').toBe(draftsBefore);
});

/** The work under the third step: the customer and one line, typed at a person's pace. */
async function draft(film: Page): Promise<void> {
  await film.getByTestId('invoice-customer').pressSequentially('Amel', { delay: 110 });
  await film.getByRole('option').filter({ hasText: 'Amel Ben Salah' }).first().click();
  await film.waitForTimeout(600);
  await film.getByTestId('line-0-product').pressSequentially('USB', { delay: 110 });
  await film.getByRole('option').filter({ hasText: 'Clé USB 64 Go' }).first().click();
  await film.waitForTimeout(500);
  await film.getByTestId('line-0-quantity').fill('');
  await film.getByTestId('line-0-quantity').pressSequentially('2', { delay: 110 });
  await film.waitForTimeout(1_500);
}

/**
 * The narration, drawn in the page away from the guide's card and never shipped with the app. Styled through the
 * CSSOM, which the page's nonce policy leaves alone, where a `<style>` element would be refused.
 */
async function caption(film: Page, text: string | null): Promise<void> {
  await film.evaluate((line) => {
    const id = 'twes-recorder-caption';
    document.getElementById(id)?.remove();
    if (line === null) return;
    const band = document.createElement('div');
    band.id = id;
    band.setAttribute('aria-hidden', 'true');
    band.textContent = line;
    const card = document.querySelector('[data-testid="tour-card"]')?.getBoundingClientRect();
    const low = card !== undefined && card.top + card.height / 2 > window.innerHeight / 2;
    const style = band.style;
    style.setProperty('position', 'fixed');
    style.setProperty('left', '50%');
    style.setProperty('transform', 'translateX(-50%)');
    style.setProperty(low ? 'top' : 'bottom', '28px');
    style.setProperty('max-width', 'min(900px, calc(100vw - 64px))');
    style.setProperty('padding', '12px 20px');
    style.setProperty('border-radius', '12px');
    style.setProperty('background', 'var(--mat-sys-inverse-surface)');
    style.setProperty('color', 'var(--mat-sys-inverse-on-surface)');
    style.setProperty('font', '500 20px/1.4 var(--mat-sys-body-large-font, sans-serif)');
    style.setProperty('text-align', 'center');
    style.setProperty('box-shadow', 'var(--twes-shadow-2, none)');
    style.setProperty('z-index', '2147483647');
    style.setProperty('pointer-events', 'none');
    document.body.append(band);
  }, text);
}

async function drafts(page: Page): Promise<number> {
  return page.evaluate(async () => {
    const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
    const response = await fetch(`/api/companies/${me.company.id}/invoice-status-counts`, {
      headers: { Accept: 'application/json' },
    });
    if (!response.ok) throw new Error(`invoice-status-counts answered ${response.status}`);
    const counts = (await response.json()) as { statuses: Record<string, number> };
    return counts.statuses['draft'] ?? 0;
  });
}

async function workIn(page: Page, name: string): Promise<void> {
  const outcome = await page.evaluate(async (wanted) => {
    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'csrf-token': crypto.randomUUID(),
    };
    const listed = await fetch('/api/me/companies', { headers });
    const companies = (await listed.json()) as { companyId: string; name: string | null }[];
    const company = companies.find((each) => each.name === wanted);
    if (company === undefined) return `no company named ${wanted}: run make fixtures`;
    const switched = await fetch('/api/me/company', {
      method: 'POST',
      headers,
      body: JSON.stringify({ companyId: company.companyId }),
    });
    return switched.ok ? 'ok' : `switching answered ${switched.status}`;
  }, name);
  if (outcome !== 'ok') throw new Error(outcome);
}
