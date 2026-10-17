// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { DemoMailsApi } from './demo-mails-api';

describe('DemoMailsApi', () => {
  let api: DemoMailsApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(DemoMailsApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads the mails kept for the company, in the order the API gives', async () => {
    const result = api.list('k 1');
    http.expectOne('/api/companies/k%201/demo-mails').flush({
      mails: [
        { id: 'm2', subject: 'Second', capturedAt: '2026-10-18T09:00:00+00:00', html: '<p>2</p>' },
        { id: 'm1', subject: 'Premier', capturedAt: '2026-10-18T08:00:00+00:00', html: '<p>1</p>' },
      ],
    });

    expect((await result).map((mail) => mail.subject)).toEqual(['Second', 'Premier']);
  });
});
