// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { RecurringApi, RecurringRefused } from './recurring-api';

describe('RecurringApi', () => {
  let api: RecurringApi;
  let http: HttpTestingController;
  const url = '/api/companies/c1/recurring-invoices';

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(RecurringApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads the list, a model still a draft with no number', async () => {
    const listed = api.list('c1');
    http.expectOne({ method: 'GET', url }).flush([
      {
        id: 'r1',
        modelInvoiceId: 'i1',
        modelNumber: null,
        customerName: 'Café du Port',
        frequency: 'quarterly',
        startsOn: '2026-10-31',
        endsOn: null,
        paused: false,
        nextOn: '2027-01-31',
        drafted: 1,
        lastInvoiceId: 'i9',
      },
    ]);

    await expect(listed).resolves.toEqual([
      expect.objectContaining({ id: 'r1', modelNumber: null, frequency: 'quarterly', drafted: 1 }),
    ]);
  });

  it('names the field a refusal was about in the screens’ words, and none it has no words for', async () => {
    const made = api.create('c1', {
      modelInvoiceId: 'i1',
      frequency: 'monthly',
      startsOn: '2026-10-01',
      endsOn: null,
    });
    http
      .expectOne({ method: 'POST', url })
      .flush(
        { detail: 'startsOn: The first draft is made today or later.' },
        { status: 422, statusText: 'Unprocessable Content' },
      );
    await expect(made).rejects.toMatchObject({ code: 'invalid', field: 'starts_on' });

    const revised = api.revise('c1', 'r1', { frequency: 'monthly', endsOn: null, paused: true });
    http
      .expectOne({ method: 'PUT', url: `${url}/r1` })
      .flush(
        { detail: 'somethingElse: no.' },
        { status: 422, statusText: 'Unprocessable Content' },
      );
    const refusal = await revised.catch((error: unknown) => error);
    expect(refusal).toBeInstanceOf(RecurringRefused);
    expect((refusal as RecurringRefused).field).toBeNull();

    const removed = api.delete('c1', 'r1');
    http
      .expectOne({ method: 'DELETE', url: `${url}/r1` })
      .flush(null, { status: 404, statusText: 'Not Found' });
    await expect(removed).rejects.toMatchObject({ code: 'not_found' });
  });
});
