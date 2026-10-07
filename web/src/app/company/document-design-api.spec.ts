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
    const pending = api.preview('c1', { layout: 'modern', accent: '#1f6feb' });
    const request = http.expectOne((req) => req.url === '/api/companies/c1/invoice-design-preview');
    expect([request.request.params.get('layout'), request.request.params.get('accent')]).toEqual([
      'modern',
      '#1f6feb',
    ]);
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
      const pending = api.preview('c1', { layout: 'classic', accent: '#1f2328' });
      http
        .expectOne((req) => req.url === '/api/companies/c1/invoice-design-preview')
        .flush(new Blob([]), { status, statusText: 'Refused' });
      await expect(pending).rejects.toEqual(new PreviewRefused(state));
    }
  });
});
