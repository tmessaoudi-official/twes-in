// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { NotificationChoicesApi } from './notification-choices-api';

describe('NotificationChoicesApi', () => {
  let api: NotificationChoicesApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(NotificationChoicesApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads every kind the person is told, company by company, with how', async () => {
    const listed = api.list();
    http.expectOne({ method: 'GET', url: '/api/me/notification-preferences' }).flush({
      preferences: [
        {
          companyId: 'c1',
          companyName: 'Acme',
          type: 'stock.low',
          bell: false,
          email: true,
          mailed: true,
        },
        {
          companyId: null,
          companyName: null,
          type: 'invitation.received',
          bell: true,
          email: true,
          mailed: false,
        },
      ],
    });

    await expect(listed).resolves.toEqual([
      {
        companyId: 'c1',
        companyName: 'Acme',
        type: 'stock.low',
        bell: false,
        email: true,
        mailed: true,
      },
      {
        companyId: null,
        companyName: null,
        type: 'invitation.received',
        bell: true,
        email: true,
        mailed: false,
      },
    ]);
  });

  it('keeps one choice, the company it is made in named', async () => {
    const changed = api.change({
      companyId: 'c1',
      companyName: 'Acme',
      type: 'stock.low',
      bell: false,
      email: true,
      mailed: true,
    });
    const request = http.expectOne({ method: 'PUT', url: '/api/me/notification-preferences' });
    expect(request.request.body).toEqual({
      companyId: 'c1',
      type: 'stock.low',
      bell: false,
      email: true,
    });
    request.flush(null, { status: 204, statusText: 'No Content' });

    await expect(changed).resolves.toBeUndefined();
  });
});
