// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { RecurringApi, RecurringRefused } from './recurring-api';
import type { RecurringInvoiceRow } from './recurring-types';

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

  it('reads the list, its schedule gathered as one, a model still a draft with no number, a month’s second Wednesday', async () => {
    const listed = api.list('c1');
    http.expectOne({ method: 'GET', url }).flush([
      {
        id: 'r1',
        modelInvoiceId: 'i1',
        modelNumber: null,
        customerName: 'Café du Port',
        unit: 'month',
        every: 3,
        anchor: 'date',
        monthDay: 31,
        ordinal: null,
        weekday: null,
        startsOn: '2026-10-31',
        endsOn: null,
        paused: false,
        nextOn: '2027-01-31',
        drafted: 1,
        lastInvoiceId: 'i9',
        version: 2,
      },
      {
        id: 'r2',
        modelInvoiceId: 'i2',
        modelNumber: 'F-2026-0007',
        customerName: 'Atelier Ben Salah',
        unit: 'month',
        every: 1,
        anchor: 'weekday',
        monthDay: null,
        ordinal: 2,
        weekday: 3,
        startsOn: '2026-11-01',
        endsOn: '2027-06-30',
        paused: true,
        nextOn: '2026-11-11',
        drafted: 0,
        lastInvoiceId: null,
        version: 5,
      },
    ]);

    await expect(listed).resolves.toEqual<RecurringInvoiceRow[]>([
      {
        id: 'r1',
        modelInvoiceId: 'i1',
        modelNumber: null,
        customerName: 'Café du Port',
        schedule: {
          unit: 'month',
          every: 3,
          anchor: 'date',
          monthDay: 31,
          ordinal: null,
          weekday: null,
        },
        startsOn: '2026-10-31',
        endsOn: null,
        paused: false,
        nextOn: '2027-01-31',
        drafted: 1,
        lastInvoiceId: 'i9',
        version: 2,
      },
      {
        id: 'r2',
        modelInvoiceId: 'i2',
        modelNumber: 'F-2026-0007',
        customerName: 'Atelier Ben Salah',
        schedule: {
          unit: 'month',
          every: 1,
          anchor: 'weekday',
          monthDay: null,
          ordinal: 2,
          weekday: 3,
        },
        startsOn: '2026-11-01',
        endsOn: '2027-06-30',
        paused: true,
        nextOn: '2026-11-11',
        drafted: 0,
        lastInvoiceId: null,
        version: 5,
      },
    ]);
  });

  it('writes the schedule flat, a day or a week with no anchor at all', async () => {
    const made = api.create('c1', {
      modelInvoiceId: 'i1',
      schedule: {
        unit: 'week',
        every: 2,
        anchor: null,
        monthDay: null,
        ordinal: null,
        weekday: null,
      },
      startsOn: '2026-10-16',
      endsOn: null,
    });
    const create = http.expectOne({ method: 'POST', url });
    expect(create.request.body).toEqual({
      modelInvoiceId: 'i1',
      unit: 'week',
      every: 2,
      monthDay: null,
      ordinal: null,
      weekday: null,
      startsOn: '2026-10-16',
      endsOn: null,
    });
    expect('anchor' in (create.request.body as object)).toBe(false);
    create.flush({ id: 'r1', unit: 'week', every: 2, anchor: null });
    await expect(made).resolves.toMatchObject({ schedule: { unit: 'week', anchor: null } });

    const revised = api.revise('c1', 'r1', {
      schedule: {
        unit: 'month',
        every: 2,
        anchor: 'last_weekday',
        monthDay: null,
        ordinal: null,
        weekday: 5,
      },
      endsOn: '2027-12-31',
      paused: true,
      version: 3,
    });
    const revise = http.expectOne({ method: 'PUT', url: `${url}/r1` });
    expect(revise.request.body).toEqual({
      unit: 'month',
      every: 2,
      anchor: 'last_weekday',
      monthDay: null,
      ordinal: null,
      weekday: 5,
      endsOn: '2027-12-31',
      paused: true,
      version: 3,
    });
    revise.flush({ id: 'r1', version: 4 });
    await expect(revised).resolves.toMatchObject({ version: 4 });
  });

  it('tells a revision refused over a version saved since from any other refusal', async () => {
    const revision = {
      schedule: {
        unit: 'day',
        every: 1,
        anchor: null,
        monthDay: null,
        ordinal: null,
        weekday: null,
      },
      endsOn: null,
      paused: true,
      version: 2,
    } as const;
    const revised = api.revise('c1', 'r1', revision);
    http
      .expectOne({ method: 'PUT', url: `${url}/r1` })
      .flush(
        { type: '/problems/record-changed', title: 'Changed', status: 409 },
        { status: 409, statusText: 'Conflict' },
      );
    await expect(revised).rejects.toMatchObject({ code: 'record_changed' });

    const conflicting = api.revise('c1', 'r1', revision);
    http
      .expectOne({ method: 'PUT', url: `${url}/r1` })
      .flush({ detail: 'no.' }, { status: 409, statusText: 'Conflict' });
    await expect(conflicting).rejects.toMatchObject({ code: 'invalid' });
  });

  it('names the field a refusal was about as the form names it, and none it has no field for', async () => {
    const made = api.create('c1', {
      modelInvoiceId: 'i1',
      schedule: {
        unit: 'month',
        every: 1,
        anchor: 'date',
        monthDay: 5,
        ordinal: null,
        weekday: null,
      },
      startsOn: '2026-10-01',
      endsOn: null,
    });
    http.expectOne({ method: 'POST', url }).flush(
      {
        violations: [
          { propertyPath: 'startsOn', message: 'past', payload: { reason: 'first_day_past' } },
        ],
      },
      { status: 422, statusText: 'Unprocessable Content' },
    );
    await expect(made).rejects.toMatchObject({ code: 'invalid', field: 'startsOn' });

    const revised = api.revise('c1', 'r1', {
      schedule: {
        unit: 'day',
        every: 1,
        anchor: null,
        monthDay: null,
        ordinal: null,
        weekday: null,
      },
      endsOn: null,
      paused: true,
      version: 1,
    });
    http
      .expectOne({ method: 'PUT', url: `${url}/r1` })
      .flush({ detail: 'no.' }, { status: 422, statusText: 'Unprocessable Content' });
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
