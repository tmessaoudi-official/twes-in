// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { InvoicesRefused } from './invoices-api';
import { RemindersApi } from './reminders-api';

describe('RemindersApi', () => {
  let api: RemindersApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(RemindersApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads the stages an invoice reached, and the late fee each drafted', async () => {
    const listed = api.list('c/1', 'f1');
    http.expectOne('/api/companies/c%2F1/invoices/f1/reminders').flush([
      { id: 'r1', stage: 1, daysLate: 8, reachedOn: '2026-10-01', lateFeeInvoiceId: null },
      { id: 'r2', stage: 2, daysLate: 16, reachedOn: '2026-10-09', lateFeeInvoiceId: 'fee1' },
      { id: 'r3', stage: 3, daysLate: 31, reachedOn: '2026-10-24' },
    ]);

    expect(await listed).toEqual([
      { id: 'r1', stage: 1, daysLate: 8, reachedOn: '2026-10-01', lateFeeInvoiceId: null },
      { id: 'r2', stage: 2, daysLate: 16, reachedOn: '2026-10-09', lateFeeInvoiceId: 'fee1' },
      { id: 'r3', stage: 3, daysLate: 31, reachedOn: '2026-10-24', lateFeeInvoiceId: null },
    ]);
  });

  it('refuses as the invoices do, by a code the screen translates', async () => {
    const listed = api.list('c1', 'f1');
    http
      .expectOne('/api/companies/c1/invoices/f1/reminders')
      .flush({}, { status: 404, statusText: 'Not Found' });

    await expect(listed).rejects.toBeInstanceOf(InvoicesRefused);
  });
});
