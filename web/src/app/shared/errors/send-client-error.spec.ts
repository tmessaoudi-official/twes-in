// SPDX-License-Identifier: AGPL-3.0-or-later

import { CSRF_HEADER, csrfToken } from '../session/csrf-token';
import { ClientErrorBounds, fitted, sendClientError } from './send-client-error';

describe('sendClientError', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('posts the report to the API with the page CSRF value, kept alive past a navigation', async () => {
    const fetch = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetch);
    const report = { message: 'm', stack: 's', page: '/invoices', build: '2026.10.12.3' };

    await sendClientError(report, BOUNDS);

    expect(fetch).toHaveBeenCalledExactlyOnceWith('/api/client-errors', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', [CSRF_HEADER]: csrfToken() },
      body: JSON.stringify(report),
      keepalive: true,
    });
  });

  it('cuts the message and the stack to the lengths the API keeps', () => {
    const report = { message: 'm'.repeat(30), stack: 's'.repeat(60), page: '/p', build: null };

    expect(fitted(report, { bytes: 16384, messageLength: 10, stackLength: 20 })).toEqual({
      ...report,
      message: 'm'.repeat(10),
      stack: 's'.repeat(20),
    });
  });

  it('cuts by character, never inside one', () => {
    const report = { message: '😀'.repeat(5), stack: '', page: '/p', build: null };

    expect(fitted(report, { bytes: 16384, messageLength: 3, stackLength: 20 }).message).toBe(
      '😀😀😀',
    );
  });

  it('shortens the stack, then the message, until the body fits the size the API reads', () => {
    // A stack within its length can still carry a body past the size: an escaped character takes six bytes.
    const report = {
      message: 'x'.repeat(100),
      stack: '\u0001'.repeat(1000),
      page: '/p',
      build: '1',
    };
    const bounds = { bytes: 400, messageLength: 500, stackLength: 4000 };

    const sent = fitted(report, bounds);

    expect(new TextEncoder().encode(JSON.stringify(sent)).length).toBeLessThanOrEqual(400);
    expect(sent.message).toBe('x'.repeat(100));
    expect(sent.stack.length).toBeGreaterThan(0);
    const tight = fitted(report, { ...bounds, bytes: 80 });
    expect(new TextEncoder().encode(JSON.stringify(tight)).length).toBeLessThanOrEqual(80);
    expect(tight.stack).toBe('');
  });

  it('sends what it cut', async () => {
    const fetch = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetch);

    await sendClientError(
      { message: 'm'.repeat(30), stack: '', page: '/p', build: null },
      { ...BOUNDS, messageLength: 4 },
    );

    expect(JSON.parse(fetch.mock.calls[0][1].body).message).toBe('mmmm');
  });
});

const BOUNDS: ClientErrorBounds = { bytes: 16384, messageLength: 500, stackLength: 4000 };
