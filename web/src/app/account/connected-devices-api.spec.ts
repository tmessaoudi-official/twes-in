// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ConnectedDevicesApi } from './connected-devices-api';

describe('ConnectedDevicesApi', () => {
  let http: HttpTestingController;
  let api: ConnectedDevicesApi;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    http = TestBed.inject(HttpTestingController);
    api = TestBed.inject(ConnectedDevicesApi);
  });

  afterEach(() => http.verify());

  it('lists the sessions', async () => {
    const answer = api.list();
    http.expectOne({ method: 'GET', url: '/api/auth/sessions' }).flush([]);
    expect(await answer).toEqual([]);
  });

  it('ends one session by its id', async () => {
    const answer = api.end('abc');
    http.expectOne({ method: 'DELETE', url: '/api/auth/sessions/abc' }).flush(null);
    await answer;
  });

  it('ends the others and says how many', async () => {
    const answer = api.endOthers();
    http.expectOne({ method: 'DELETE', url: '/api/auth/sessions' }).flush({ ended: 3 });
    expect(await answer).toBe(3);
  });
});
