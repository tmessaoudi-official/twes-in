// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CSRF_HEADER } from '../session/csrf-token';
import { PairingApi } from './pairing-api';

describe('PairingApi.endOnLeave', () => {
  let fetchSpy: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    fetchSpy = vi.fn(async () => new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchSpy);
    TestBed.configureTestingModule({ providers: [provideHttpClient()] });
  });

  afterEach(() => vi.unstubAllGlobals());

  it('sends the end as a keepalive request carrying the CSRF header, which outlives the closing page', () => {
    TestBed.inject(PairingApi).endOnLeave('c-1', 'p-1');

    const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit];
    expect(url).toMatch(/\/c-1\/.*\/p-1$|\/c-1\/.*p-1$/);
    expect(init.method).toBe('DELETE');
    expect(init.keepalive).toBe(true);
    expect(Object.keys(init.headers as Record<string, string>)).toContain(CSRF_HEADER);
  });

  it('says nothing when the request cannot be sent', async () => {
    fetchSpy.mockRejectedValue(new TypeError('offline'));

    expect(() => TestBed.inject(PairingApi).endOnLeave('c-1', 'p-1')).not.toThrow();
    await Promise.resolve();
  });
});
