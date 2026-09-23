// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { wcagViolations } from './axe';
import { aProduct, forget, gtin, withCodes } from './catalogue';
import { fakeCameraShowing } from './fake-camera';
import { inACompany, signIn } from './session';

// docs/SPEC.md § 7, 2026-09-23 09:45, slice 3: the device's camera scans like a handheld scanner, continuously, and
// what it reads goes where a scanner's keystrokes go. Chrome's fake camera plays a picture of a real EAN-13, so the
// self-hosted decoder, the page's CSP and the whole path to the invoice line are all exercised.
const CSRF = '0123456789abcdef0123456789abcdef';
const code = gtin(`200${String(Date.now() % 1_000_000_000).padStart(9, '0')}`);

test.use({
  permissions: ['camera'],
  launchOptions: {
    args: [
      '--use-fake-ui-for-media-stream',
      '--use-fake-device-for-media-stream',
      `--use-file-for-fake-video-capture=${fakeCameraShowing(code)}`,
    ],
  },
});

test('the camera puts a product in view on the draft invoice once, as a till counts one item', async ({
  page,
}) => {
  const run = Date.now().toString(36).toUpperCase();
  await signIn(page);
  await inACompany(page, CSRF);
  const ids: string[] = [];
  try {
    ids.push(await aProduct(page, `CAM-${run}`));
    await withCodes(page, ids[0], [code]);

    await page.goto('/invoices/new');
    await expect(page.getByTestId('line-0-description')).toBeVisible();
    await page.getByTestId('camera-open').click();
    await expect(page.getByTestId('camera-scan-panel')).toBeVisible();

    await expect(page.getByTestId('line-0-description')).toHaveValue(`Vis CAM-${run}`, {
      timeout: 20_000,
    });
    await expect(page.getByTestId('camera-last-read')).toContainText(code);
    // The code stays in view: a camera sees it on every frame, and it still counts once.
    await page.waitForTimeout(2_000);
    await expect(page.getByTestId('line-0-quantity')).toHaveValue('1');
    await expect(page.getByTestId('line-1')).toHaveCount(0);
    expect(await wcagViolations(page)).toEqual([]);

    await page.getByTestId('camera-close').click();
    await expect(page.getByTestId('camera-scan-panel')).toHaveCount(0);
  } finally {
    await forget(page, ids);
  }
});

test('the decoder ships with the licence texts of what it compiles in, beside it', async ({
  page,
}) => {
  // Served from our own origin, never the package's CDN default, and each notice travels with the binary.
  const wasm = await page.request.get('/vendor/zxing-reader/zxing_reader.wasm');
  expect(wasm.headers()['content-type']).toBe('application/wasm');
  const manifest = (await (
    await page.request.get('/vendor/zxing-reader/COMPONENTS.json')
  ).json()) as { components: { text: string }[] };
  expect(manifest.components.length).toBeGreaterThanOrEqual(10);
  for (const { text } of manifest.components) {
    const served = await page.request.get(`/vendor/zxing-reader/${text}`);
    expect(served.headers()['content-type'], text).toContain('text/plain');
    expect((await served.text()).length, text).toBeGreaterThan(100);
  }
});
