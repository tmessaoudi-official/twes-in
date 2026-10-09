// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CSRF_HEADER } from '../session/csrf-token';
import { PairingApi } from './pairing-api';

const SCAN_ID = '0199aaaa-0000-4000-8000-0000000000f1';

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

describe('PairingApi photos', () => {
  let http: HttpTestingController;
  let api: PairingApi;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    http = TestBed.inject(HttpTestingController);
    api = TestBed.inject(PairingApi);
  });

  afterEach(() => http.verify());

  it('sends the phone’s photo with its key, as a picture beside the id the echo will answer', async () => {
    const photo = new Blob(['jpeg'], { type: 'image/jpeg' });
    const sent = api.photo('p-1', 'k'.repeat(64), SCAN_ID, photo);

    const request = http.expectOne('/api/scan-pairings/p-1/photos');
    expect(request.request.method).toBe('POST');
    expect(request.request.headers.get('X-Pairing-Key')).toBe('k'.repeat(64));
    const body = request.request.body as FormData;
    expect(body.get('scan')).toBe(SCAN_ID);
    expect((body.get('file') as File).type).toBe('image/jpeg');
    request.flush(null, { status: 202, statusText: 'Accepted' });
    await expect(sent).resolves.toBeNull();
  });

  it('takes the photo once, as the picture it is', async () => {
    const taken = api.takePhoto('c-1', 'p-1', 'ph-1');

    const request = http.expectOne('/api/companies/c-1/scan-pairings/p-1/photos/ph-1/take');
    expect(request.request.method).toBe('POST');
    expect(request.request.responseType).toBe('blob');
    request.flush(new Blob(['jpeg'], { type: 'image/jpeg' }));
    expect((await taken).type).toBe('image/jpeg');
  });
});
