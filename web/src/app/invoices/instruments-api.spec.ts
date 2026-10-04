// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { InstrumentsApi } from './instruments-api';

const wire = {
  id: 'i1',
  kind: 'check',
  amount: '500.000',
  dueOn: '2026-11-15',
  bank: 'BT',
  number: 'CHQ-77',
  status: 'held',
  settledOn: null,
  paymentId: null,
};

describe('InstrumentsApi', () => {
  let api: InstrumentsApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(InstrumentsApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it("lists an invoice's instruments and reads each as the screen holds it", async () => {
    const listed = api.list('c/1', 'f1');
    http.expectOne('/api/companies/c%2F1/invoices/f1/instruments').flush([
      wire,
      {
        ...wire,
        id: 'i2',
        kind: 'draft',
        bank: null,
        number: null,
        status: 'cashed',
        settledOn: '2026-11-16',
        paymentId: 'p1',
      },
    ]);
    expect(await listed).toEqual([
      {
        id: 'i1',
        kind: 'check',
        amount: '500.000',
        dueOn: '2026-11-15',
        bank: 'BT',
        number: 'CHQ-77',
        status: 'held',
        settledOn: null,
        paymentId: null,
      },
      {
        id: 'i2',
        kind: 'draft',
        amount: '500.000',
        dueOn: '2026-11-15',
        bank: null,
        number: null,
        status: 'cashed',
        settledOn: '2026-11-16',
        paymentId: 'p1',
      },
    ]);
  });

  it('receives one, and says why it was refused', async () => {
    const input = {
      kind: 'check',
      amount: '500',
      dueOn: '2026-11-15',
      bank: 'BT',
      number: null,
    } as const;
    const received = api.receive('c1', 'f1', input);
    const post = http.expectOne('/api/companies/c1/invoices/f1/instruments');
    expect([post.request.method, post.request.body]).toEqual(['POST', input]);
    post.flush(wire);
    expect((await received).id).toBe('i1');

    const refused = api.receive('c1', 'f1', input);
    http
      .expectOne('/api/companies/c1/invoices/f1/instruments')
      .flush({ detail: 'amount' }, { status: 422, statusText: 'Unprocessable Entity' });
    await expect(refused).rejects.toMatchObject({ code: 'invalid' });
  });

  it('takes each step on a route of its own, and a conflict reads as one', async () => {
    for (const step of ['deposit', 'cash', 'unpaid'] as const) {
      const moved = api.advance('c1', 'f1', 'i 1', step);
      const post = http.expectOne(`/api/companies/c1/invoices/f1/instruments/i%201/${step}`);
      expect(post.request.method).toBe('POST');
      post.flush(wire);
      await moved;
    }
    const refused = api.advance('c1', 'f1', 'i1', 'cash');
    http
      .expectOne('/api/companies/c1/invoices/f1/instruments/i1/cash')
      .flush({}, { status: 409, statusText: 'Conflict' });
    await expect(refused).rejects.toMatchObject({ code: 'conflict' });
  });

  it('deletes a held one, and says when it is gone or kept', async () => {
    const removed = api.remove('c1', 'f1', 'i1');
    const request = http.expectOne('/api/companies/c1/invoices/f1/instruments/i1');
    expect(request.request.method).toBe('DELETE');
    request.flush(null, { status: 204, statusText: 'No Content' });
    await removed;

    const kept = api.remove('c1', 'f1', 'i1');
    http
      .expectOne('/api/companies/c1/invoices/f1/instruments/i1')
      .flush({}, { status: 409, statusText: 'Conflict' });
    await expect(kept).rejects.toMatchObject({ code: 'conflict' });
  });
});
