// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';

/**
 * The API documentation, reached on the application's own origin (docs/SPEC.md § 7, 2026-09-22).
 *
 * This whole class of defect answers 200 to everything and is invisible to a status-code check. The page's
 * stylesheets and scripts live under `/bundles/`, which is not under `/api/`: with nothing routing that path they
 * fell through to the single-page application's catch-all, so every one of them came back as `index.html` with
 * `Content-Type: text/html`, which `nosniff` then refused to run. Swagger rendered as unstyled text.
 *
 * None of these requests needs a session: they are static files and a redirect.
 */
test.describe('the API documentation', () => {
  test('serves its own stylesheets and scripts, and not the application shell', async ({
    request,
  }) => {
    const style = await request.get('/bundles/apiplatform/style.css');
    expect(style.status()).toBe(200);
    expect(style.headers()['content-type']).toContain('text/css');

    const script = await request.get('/bundles/apiplatform/swagger-ui/swagger-ui-bundle.js');
    expect(script.status()).toBe(200);
    expect(script.headers()['content-type']).toContain('javascript');
    // The shell is around 19 kB; the real bundle is over a megabyte. A size floor says which one answered even
    // if some future rewrite gets the content type right while still serving the wrong file.
    expect((await script.body()).byteLength).toBeGreaterThan(500_000);
  });

  /**
   * nginx answers `/api` with its own 301 to `/api/`, because that location proxies and ends in a slash. Built
   * absolutely it carries the LISTEN port, which is 80 inside the container, so a person on :8090 was sent to
   * http://localhost/api/ — a port nothing answers on. The redirect must stay on the origin it was asked from.
   */
  test('redirects /api onto the same origin, port and all', async ({ request }) => {
    const answer = await request.get('/api', { maxRedirects: 0 });

    expect(answer.status()).toBe(301);
    const where = answer.headers()['location'];
    expect(where).toBe('/api/');
    expect(where).not.toContain('//localhost/');
  });
});
