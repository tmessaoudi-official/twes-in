// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { StopMailApi, StopMailRefused } from './stop-mail-api';

describe('StopMailApi', () => {
  let api: StopMailApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(StopMailApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('posts the link token, never a GET', async () => {
    const stopped = api.stop('the-token');
    const request = http.expectOne({ method: 'POST', url: '/api/notification-preferences/stop' });
    expect(request.request.body).toEqual({ token: 'the-token' });
    request.flush(null, { status: 204, statusText: 'No Content' });

    await expect(stopped).resolves.toBeUndefined();
  });

  it('names why the API refused it', async () => {
    for (const [status, code] of [
      [404, 'link_not_usable'],
      [429, 'too_many_attempts'],
      [500, 'network'],
    ] as const) {
      const stopped = api.stop('the-token');
      http
        .expectOne('/api/notification-preferences/stop')
        .flush({ error: 'whatever' }, { status, statusText: 'Refused' });

      await expect(stopped).rejects.toEqual(new StopMailRefused(code));
    }
  });
});
