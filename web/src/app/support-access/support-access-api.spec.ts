// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { SupportAccessApi, SupportAccessRefused } from './support-access-api';

describe('SupportAccessApi', () => {
  let api: SupportAccessApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(SupportAccessApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('opens the company to support for the hours chosen', async () => {
    const opened = api.grant('c1', 24);
    const request = http.expectOne('/api/companies/c1/support-access');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ hours: 24 });
    request.flush({ startsAt: 'a', endsAt: 'b', hours: [1, 24, 72] });
    expect((await opened).endsAt).toBe('b');
  });

  // docs/SPEC.md § 7, the step-up list: opening the company to support waits for the owner's recent proof.
  it('names each refusal the page translates, the proof the API waits for among them', async () => {
    for (const code of ['owner_only', 'support_window', 'step_up_required'] as const) {
      const refused = api.grant('c1', 24);
      http
        .expectOne('/api/companies/c1/support-access')
        .flush(
          { error: code },
          { status: code === 'support_window' ? 422 : 403, statusText: 'No' },
        );
      await expect(refused).rejects.toEqual(new SupportAccessRefused(code));
    }

    const gone = api.read('c1');
    http
      .expectOne('/api/companies/c1/support-access')
      .flush(null, { status: 404, statusText: 'Not Found' });
    await expect(gone).rejects.toEqual(new SupportAccessRefused('not_found'));
  });
});
