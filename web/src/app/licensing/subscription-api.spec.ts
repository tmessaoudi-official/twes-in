// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { SubscriptionApi, SubscriptionRefused } from './subscription-api';

const payment = {
  id: 'p1',
  amount: '600.000',
  currency: 'TND',
  method: 'cash',
  paidOn: '2026-09-16',
  reference: 'REC-12',
  note: null,
  status: 'declared',
  declaredAt: '2026-09-16T10:00:00+00:00',
  decidedAt: null,
  decisionNote: null,
};

describe('SubscriptionApi', () => {
  let api: SubscriptionApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(SubscriptionApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it("reads the company's standing with what it declared, and answers null where nothing is managed", async () => {
    const pending = api.ofCompany('c1');
    http.expectOne({ method: 'GET', url: '/api/companies/c1/subscription' }).flush({
      companyId: 'c1',
      stage: 'held',
      access: 'full',
      coveredUntil: '2026-08-18T23:59:59+01:00',
      graceEndsAt: '2026-08-25T23:59:59+01:00',
      daysLeft: 6,
      trialEndsOn: null,
      paidThrough: '2026-08-18',
      periodCount: 1,
      periodUnit: 'month',
      price: '600.000',
      currency: 'TND',
      openPayment: payment,
      payments: [payment],
      canDeclare: false,
    });

    const view = await pending;
    expect(view?.stage).toBe('held');
    expect(view?.openPayment?.amount).toBe('600.000');
    expect(view?.payments).toHaveLength(1);
    expect(view?.canDeclare).toBe(false);

    const none = api.ofCompany('c2');
    http
      .expectOne('/api/companies/c2/subscription')
      .flush(null, { status: 404, statusText: 'Not Found' });
    await expect(none).resolves.toBeNull();
  });

  it('reads a body that leaves out what is null, which is what the API sends', async () => {
    // API Platform drops a null property unless an operation says otherwise, and a page that breaks on a missing
    // key shows "the server did not answer" for a company whose standing is perfectly readable.
    const pending = api.ofCompany('c1');
    http.expectOne('/api/companies/c1/subscription').flush({
      companyId: 'c1',
      stage: 'unpaid',
      access: 'read_only',
      coveredUntil: '2026-08-18T23:59:59+01:00',
      graceEndsAt: '2026-08-25T23:59:59+01:00',
      daysLeft: null,
      periodCount: 1,
      periodUnit: 'month',
      payments: [],
      canDeclare: true,
    });

    const view = await pending;
    expect(view?.openPayment).toBeNull();
    expect(view?.payments).toEqual([]);
    expect(view?.paidThrough).toBeNull();
    expect(view?.price).toBeNull();
  });

  it('declares a payment', async () => {
    const declared = api.declare('c1', {
      amount: '600.000',
      currency: 'TND',
      method: 'cash',
      paidOn: '2026-09-16',
      reference: 'REC-12',
      note: null,
    });
    const request = http.expectOne({
      method: 'POST',
      url: '/api/companies/c1/subscription/payments',
    });
    expect(request.request.body).toEqual({
      amount: '600.000',
      currency: 'TND',
      method: 'cash',
      paidOn: '2026-09-16',
      reference: 'REC-12',
      note: null,
    });
    request.flush(payment);

    expect((await declared).status).toBe('declared');
  });

  it("reads the operator's queue and answers a payment either way", async () => {
    const queue = api.waiting();
    http
      .expectOne({ method: 'GET', url: '/api/platform/payment-declarations' })
      .flush([{ ...payment, companyId: 'c1', companyName: 'Acme' }]);
    expect((await queue)[0].companyName).toBe('Acme');

    const confirming = api.confirm('p1', 2, 'reçu');
    const confirm = http.expectOne('/api/platform/payment-declarations/p1/confirm');
    expect(confirm.request.body).toEqual({ periods: 2, decisionNote: 'reçu' });
    confirm.flush({ ...payment, status: 'confirmed' });
    expect((await confirming).status).toBe('confirmed');

    // A rejection names no periods: it covers nothing.
    const rejecting = api.reject('p1', null);
    const reject = http.expectOne('/api/platform/payment-declarations/p1/reject');
    expect(reject.request.body).toEqual({ decisionNote: null });
    reject.flush({ ...payment, status: 'rejected' });
    expect((await rejecting).status).toBe('rejected');
  });

  it('turns each refusal into a code the page translates', async () => {
    const declaring = api.declare('c1', {
      amount: '600.000',
      currency: 'TND',
      method: 'cash',
      paidOn: '2026-09-16',
      reference: null,
      note: null,
    });
    http
      .expectOne('/api/companies/c1/subscription/payments')
      .flush({}, { status: 409, statusText: 'Conflict' });
    await expect(declaring).rejects.toEqual(new SubscriptionRefused('already_declared'));

    const refused = api.declare('c1', {
      amount: '0',
      currency: 'TND',
      method: 'cash',
      paidOn: '2026-09-16',
      reference: null,
      note: null,
    });
    http
      .expectOne('/api/companies/c1/subscription/payments')
      .flush({}, { status: 422, statusText: 'Unprocessable Entity' });
    await expect(refused).rejects.toEqual(new SubscriptionRefused('invalid'));

    const unreachable = api.waiting();
    http.expectOne('/api/platform/payment-declarations').error(new ProgressEvent('error'));
    await expect(unreachable).rejects.toEqual(new SubscriptionRefused('network'));
  });
});
