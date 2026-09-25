// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ExpensesApi, ExpensesRefused } from './expenses-api';
import type { ExpenseInput } from './expenses-types';

const fuel: ExpenseInput = {
  date: '2026-09-10',
  reference: 'F-2026-118',
  description: 'Gasoil',
  vendorId: 'v1',
  categoryId: 'k1',
  amountNet: '100.000',
  taxComponentId: 't1',
  notes: null,
};

describe('ExpensesApi', () => {
  let api: ExpensesApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(ExpensesApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads an expense with the figures the API worked out, and no value where it has none', async () => {
    const pending = api.expense('c1', 'e 1');
    http.expectOne('/api/companies/c1/expenses/e%201').flush({
      id: 'e 1',
      status: 'recorded',
      date: '2026-09-10',
      description: 'Gasoil',
      amountNet: '100.000',
      taxRate: '19.000',
      taxAmount: '19.000',
      amountGross: '119.000',
      currency: 'TND',
      attachmentCount: 2,
    });

    expect(await pending).toEqual({
      id: 'e 1',
      status: 'recorded',
      date: '2026-09-10',
      reference: null,
      description: 'Gasoil',
      vendorId: null,
      vendorName: null,
      categoryId: null,
      categoryName: null,
      amountNet: '100.000',
      taxComponentId: null,
      taxRate: '19.000',
      taxAmount: '19.000',
      amountGross: '119.000',
      currency: 'TND',
      dueDate: null,
      paymentMethod: null,
      paidOn: null,
      notes: null,
      attachmentCount: 2,
      withholdingRate: null,
      withholdingAmount: null,
      amountPaid: '119.000',
      suggestedWithholdingRate: null,
    });
  });

  it('asks the API for one page of expenses, with what it searches, narrows and sorts by', async () => {
    const pending = api.expenses('c1', {
      page: 2,
      itemsPerPage: 50,
      q: '  gasoil  ',
      status: 'recorded',
      vendorId: 'v1',
      categoryId: 'k1',
      order: { key: 'amountGross', direction: 'desc' },
    });
    const request = http.expectOne(
      (candidate) => candidate.url === '/api/companies/c1/expenses' && candidate.method === 'GET',
    );
    expect(request.request.headers.get('Accept')).toBe('application/ld+json');
    expect(request.request.params.get('page')).toBe('2');
    expect(request.request.params.get('itemsPerPage')).toBe('50');
    // Trimmed, so a trailing space is not a different search.
    expect(request.request.params.get('q')).toBe('gasoil');
    expect(request.request.params.get('status')).toBe('recorded');
    expect(request.request.params.get('vendorId')).toBe('v1');
    expect(request.request.params.get('categoryId')).toBe('k1');
    expect(request.request.params.get('order[amountGross]')).toBe('desc');
    request.flush({
      member: [{ id: 'e1', description: 'Gasoil', amountGross: '119.000' }],
      totalItems: 64,
    });

    const page = await pending;
    expect(page.rows.map((row) => row.description)).toEqual(['Gasoil']);
    expect(page.total).toBe(64);
  });

  it('refuses a page that came without its total, rather than showing one page as the whole list', async () => {
    const pending = api.expenses('c1', {
      page: 1,
      itemsPerPage: 25,
      q: '',
      status: null,
      vendorId: null,
      categoryId: null,
      order: null,
    });
    const request = http.expectOne(
      (candidate) => candidate.url === '/api/companies/c1/expenses' && candidate.method === 'GET',
    );
    expect(request.request.params.has('q')).toBe(false);
    expect(request.request.params.has('status')).toBe(false);
    request.flush({ member: [] });
    await expect(pending).rejects.toThrow();
  });

  it('sends what was typed, records and pays on their own addresses', async () => {
    const created = api.createExpense('c1', fuel);
    const post = http.expectOne('/api/companies/c1/expenses');
    expect(post.request.method).toBe('POST');
    expect(post.request.body).toEqual(fuel);
    post.flush({ ...fuel, id: 'e1', status: 'draft' });
    await created;

    const recorded = api.recordExpense('c1', 'e1');
    const record = http.expectOne('/api/companies/c1/expenses/e1/record');
    expect(record.request.method).toBe('POST');
    record.flush({ id: 'e1', status: 'recorded' });
    expect((await recorded).status).toBe('recorded');

    const paid = api.payExpense('c1', 'e1', {
      paymentMethod: 'cash',
      paidOn: '2026-09-12',
      withholdingRate: '1',
    });
    const pay = http.expectOne('/api/companies/c1/expenses/e1/pay');
    expect(pay.request.body).toEqual({
      paymentMethod: 'cash',
      paidOn: '2026-09-12',
      withholdingRate: '1',
    });
    pay.flush({
      id: 'e1',
      status: 'paid',
      paymentMethod: 'cash',
      paidOn: '2026-09-12',
      amountGross: '1190.000',
      withholdingRate: '1.000',
      withholdingAmount: '11.900',
      amountPaid: '1178.100',
    });
    expect(await paid).toMatchObject({
      status: 'paid',
      paymentMethod: 'cash',
      withholdingRate: '1.000',
      withholdingAmount: '11.900',
      amountPaid: '1178.100',
    });
  });

  it('tells a refused expense from a refused category and a refused file', async () => {
    const recorded = api.reviseExpense('c1', 'e1', fuel);
    http
      .expectOne('/api/companies/c1/expenses/e1')
      .flush(null, { status: 409, statusText: 'Conflict' });
    await expect(recorded).rejects.toEqual(new ExpensesRefused('not_draft'));

    const invalid = api.createExpense('c1', fuel);
    http
      .expectOne('/api/companies/c1/expenses')
      .flush(null, { status: 422, statusText: 'Unprocessable Content' });
    await expect(invalid).rejects.toEqual(new ExpensesRefused('invalid'));

    const taken = api.createCategory('c1', { name: 'Carburant', parentId: null, isActive: true });
    http
      .expectOne('/api/companies/c1/expense-categories')
      .flush(null, { status: 409, statusText: 'Conflict' });
    await expect(taken).rejects.toEqual(new ExpensesRefused('name_taken'));

    const file = new File(['Bonjour'], 'facture.pdf', { type: 'application/pdf' });
    const refused = api.attach('c1', 'e1', file);
    http
      .expectOne('/api/companies/c1/expenses/e1/attachments')
      .flush(null, { status: 422, statusText: 'Unprocessable Content' });
    await expect(refused).rejects.toEqual(new ExpensesRefused('file_refused'));

    const large = api.attach('c1', 'e1', file);
    http
      .expectOne('/api/companies/c1/expenses/e1/attachments')
      .flush(null, { status: 413, statusText: 'Payload Too Large' });
    await expect(large).rejects.toEqual(new ExpensesRefused('file_too_large'));

    const kept = api.detach('c1', 'e1', 'a1');
    http
      .expectOne('/api/companies/c1/expenses/e1/attachments/a1')
      .flush(null, { status: 409, statusText: 'Conflict' });
    await expect(kept).rejects.toEqual(new ExpensesRefused('not_draft'));
  });

  it('uploads a file as one multipart part named file, and opens it at a same-origin address', async () => {
    const file = new File(['%PDF-1.4'], 'reçu.pdf', { type: 'application/pdf' });
    const pending = api.attach('c1', 'e1', file);
    const request = http.expectOne('/api/companies/c1/expenses/e1/attachments');
    expect(request.request.body).toBeInstanceOf(FormData);
    const part = (request.request.body as FormData).get('file') as File;
    expect(part.name).toBe('reçu.pdf');
    request.flush({
      id: 'a1',
      name: 'reçu.pdf',
      mime: 'application/pdf',
      size: 8,
      createdAt: '2026-09-15T08:00:00+00:00',
    });
    expect((await pending).mime).toBe('application/pdf');

    expect(api.attachmentUrl('c1', 'e1', 'a 1')).toBe(
      '/api/companies/c1/expenses/e1/attachments/a%201/content',
    );
  });

  it('reads the options and the categories', async () => {
    const options = api.options('c1');
    http.expectOne('/api/companies/c1/expense-options').flush({
      currency: 'TND',
      currencyScale: 3,
      categories: [{ id: 'k1', name: 'Carburant', parentId: null }],
      taxes: [{ id: 't1', code: 'TVA19', name: 'TVA', rate: '19.000' }],
      paymentMethods: ['transfer', 'cash'],
    });
    expect(await options).toEqual({
      currency: 'TND',
      currencyScale: 3,
      categories: [{ id: 'k1', name: 'Carburant', parentId: null }],
      taxes: [{ id: 't1', code: 'TVA19', name: 'TVA', rate: '19.000' }],
      paymentMethods: ['transfer', 'cash'],
    });

    // The book of suppliers is asked for a few at a time, never handed over with the options.
    const picked = api.pickVendors('c1', { words: ' sotu ' });
    const ask = http.expectOne(
      (request) => request.url === '/api/companies/c1/expense-options/vendors',
    );
    expect(ask.request.params.get('q')).toBe('sotu');
    ask.flush([
      {
        id: 'v1',
        number: 'FRN-1',
        name: 'Sotumag',
        paymentTermsDays: 30,
        defaultExpenseCategoryId: 'k1',
      },
    ]);
    expect(await picked).toEqual([
      {
        id: 'v1',
        number: 'FRN-1',
        name: 'Sotumag',
        paymentTermsDays: 30,
        defaultExpenseCategoryId: 'k1',
      },
    ]);

    const categories = api.categories('c1');
    http
      .expectOne('/api/companies/c1/expense-categories')
      .flush([{ id: 'k1', name: 'Carburant', parentId: null, isActive: false }]);
    expect(await categories).toEqual([
      { id: 'k1', name: 'Carburant', parentId: null, isActive: false },
    ]);
  });
});
