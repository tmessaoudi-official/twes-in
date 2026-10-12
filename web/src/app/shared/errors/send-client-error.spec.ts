// SPDX-License-Identifier: AGPL-3.0-or-later

import { CSRF_HEADER, csrfToken } from '../session/csrf-token';
import { sendClientError } from './send-client-error';

describe('sendClientError', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('posts the report to the API with the page CSRF value, kept alive past a navigation', async () => {
    const fetch = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetch);
    const report = { message: 'm', stack: 's', page: '/invoices', build: '2026.10.12.3' };

    await sendClientError(report);

    expect(fetch).toHaveBeenCalledExactlyOnceWith('/api/client-errors', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', [CSRF_HEADER]: csrfToken() },
      body: JSON.stringify(report),
      keepalive: true,
    });
  });
});
