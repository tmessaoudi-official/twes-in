// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { DocumentDesignApi, PreviewRefused } from './document-design-api';

describe('DocumentDesignApi', () => {
  let api: DocumentDesignApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(DocumentDesignApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('asks for the design as the query says it and hands back the picture as data the page may show', async () => {
    const pending = api.preview('c1', {
      layout: 'modern',
      accent: '#1f6feb',
      logoWidth: 60,
      logoHeight: 25,
      logoKeepsProportions: false,
    });
    const request = http.expectOne((req) => req.url === '/api/companies/c1/invoice-design-preview');
    const params = request.request.params;
    expect(
      ['layout', 'accent', 'logoWidth', 'logoHeight', 'logoProportions'].map((name) =>
        params.get(name),
      ),
    ).toEqual(['modern', '#1f6feb', '60', '25', 'free']);
    expect(request.request.responseType).toBe('blob');
    request.flush(new Blob(['PNG'], { type: 'image/png' }));

    expect(await pending).toBe(`data:image/png;base64,${btoa('PNG')}`);
  });

  it('names why there is no picture: no invoice yet, invoices not readable, a failure, no server', async () => {
    for (const [status, state] of [
      [404, 'nothing'],
      [403, 'forbidden'],
      [503, 'failed'],
      [0, 'network'],
    ] as const) {
      const pending = api.preview('c1', {
        layout: 'classic',
        accent: '#1f2328',
        logoWidth: null,
        logoHeight: null,
        logoKeepsProportions: true,
      });
      http
        // A room not read yet is left to the API, which takes the company's own.
        .expectOne(
          (req) =>
            req.url === '/api/companies/c1/invoice-design-preview' &&
            !req.params.has('logoWidth') &&
            !req.params.has('logoHeight'),
        )
        .flush(new Blob([]), { status, statusText: 'Refused' });
      await expect(pending).rejects.toEqual(new PreviewRefused(state));
    }
  });

  it("reads the logo's own proportions, width over height, and says null when there is no logo", async () => {
    vi.stubGlobal(
      'createImageBitmap',
      vi.fn(async () => ({ width: 900, height: 300, close: vi.fn() })),
    );
    const read = api.logoRatio('c1');
    http.expectOne('/api/companies/c1/logo').flush(new Blob(['PNG'], { type: 'image/png' }));
    expect(await read).toBe(3);

    const none = api.logoRatio('c1');
    http
      .expectOne('/api/companies/c1/logo')
      .flush(new Blob([]), { status: 404, statusText: 'Not Found' });
    expect(await none).toBeNull();
    vi.unstubAllGlobals();
  });
});
