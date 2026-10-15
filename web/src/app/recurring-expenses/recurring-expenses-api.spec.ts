// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { RecurringExpensesApi, RecurringExpenseRefused } from './recurring-expenses-api';
import type { RecurringExpenseRow } from './recurring-expenses-types';

const ROW = {
  id: 'r1',
  modelExpenseId: 'e1',
  description: 'Loyer du local',
  payee: 'SCI Les Halles',
  amountGross: '1200.000',
  currency: 'TND',
  unit: 'month',
  every: 1,
  anchor: 'last_weekday',
  monthDay: null,
  ordinal: null,
  weekday: 5,
  startsOn: '2026-10-30',
  endsOn: null,
  variableAmount: true,
  paused: false,
  nextOn: '2026-11-27',
  drafted: 1,
  lastExpenseId: 'e9',
};

describe('RecurringExpensesApi', () => {
  let api: RecurringExpensesApi;
  let http: HttpTestingController;
  const url = '/api/companies/c1/recurring-expenses';

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(RecurringExpensesApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads the list, its schedule gathered as one', async () => {
    const listed = api.list('c1');
    http.expectOne({ method: 'GET', url }).flush([ROW]);

    await expect(listed).resolves.toEqual<RecurringExpenseRow[]>([
      {
        id: 'r1',
        modelExpenseId: 'e1',
        description: 'Loyer du local',
        payee: 'SCI Les Halles',
        amountGross: '1200.000',
        currency: 'TND',
        schedule: {
          unit: 'month',
          every: 1,
          anchor: 'last_weekday',
          monthDay: null,
          ordinal: null,
          weekday: 5,
        },
        startsOn: '2026-10-30',
        endsOn: null,
        variableAmount: true,
        paused: false,
        nextOn: '2026-11-27',
        drafted: 1,
        lastExpenseId: 'e9',
      },
    ]);
  });

  it('writes the schedule flat, a day or a week with no anchor at all', async () => {
    const made = api.create('c1', {
      modelExpenseId: 'e1',
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
      variableAmount: false,
    });
    const request = http.expectOne({ method: 'POST', url });
    expect(request.request.body).toEqual({
      modelExpenseId: 'e1',
      unit: 'week',
      every: 2,
      monthDay: null,
      ordinal: null,
      weekday: null,
      startsOn: '2026-10-16',
      endsOn: null,
      variableAmount: false,
    });
    request.flush({ ...ROW, unit: 'week', every: 2, anchor: null, weekday: null });
    await expect(made).resolves.toMatchObject({ schedule: { unit: 'week', anchor: null } });

    const revised = api.revise('c1', 'r1', {
      schedule: {
        unit: 'month',
        every: 3,
        anchor: 'date',
        monthDay: 31,
        ordinal: null,
        weekday: null,
      },
      endsOn: '2027-12-31',
      variableAmount: true,
      paused: true,
    });
    const revise = http.expectOne({ method: 'PUT', url: `${url}/r1` });
    expect(revise.request.body).toEqual({
      unit: 'month',
      every: 3,
      anchor: 'date',
      monthDay: 31,
      ordinal: null,
      weekday: null,
      endsOn: '2027-12-31',
      variableAmount: true,
      paused: true,
    });
    revise.flush(ROW);
    await revised;
  });

  it('names the field a refusal was about as the form names it, and none it has no field for', async () => {
    const made = api.create('c1', {
      modelExpenseId: 'e1',
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
      variableAmount: false,
    });
    http.expectOne({ method: 'POST', url }).flush(
      {
        violations: [
          { propertyPath: 'startsOn', message: 'past', payload: { reason: 'day_past' } },
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
      variableAmount: false,
      paused: false,
    });
    http
      .expectOne({ method: 'PUT', url: `${url}/r1` })
      .flush({ detail: 'no.' }, { status: 422, statusText: 'Unprocessable Content' });
    const refusal = await revised.catch((error: unknown) => error);
    expect(refusal).toBeInstanceOf(RecurringExpenseRefused);
    expect((refusal as RecurringExpenseRefused).field).toBeNull();

    const removed = api.delete('c1', 'r1');
    http
      .expectOne({ method: 'DELETE', url: `${url}/r1` })
      .flush(null, { status: 404, statusText: 'Not Found' });
    await expect(removed).rejects.toMatchObject({ code: 'not_found' });

    const listed = api.list('c1');
    http.expectOne({ method: 'GET', url }).error(new ProgressEvent('error'));
    await expect(listed).rejects.toMatchObject({ code: 'network' });
  });
});
