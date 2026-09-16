// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { InvoicesApi, InvoicesRefused } from './invoices-api';
import { InvoicesFacade } from './invoices-facade';
import type { InvoiceInput, InvoiceOptions, InvoiceRow } from './invoices-types';

const options: InvoiceOptions = {
  currency: 'TND',
  currencyScale: 3,
  establishments: [],
  customers: [],
  products: [],
  units: [],
  taxes: [],
};
const draft: InvoiceRow = {
  id: 'i1',
  type: 'invoice',
  correctsInvoiceId: null,
  number: null,
  status: 'draft',
  customerId: 'k1',
  customerName: null,
  establishmentId: 'e1',
  issueDate: null,
  dueDate: null,
  supplyDate: null,
  paymentTermsDays: null,
  customerReference: null,
  notesPrinted: null,
  notesInternal: null,
  discountAmount: null,
  documentTaxComponentIds: [],
  lines: [],
  subtotalNet: '0.000',
  documentDiscount: '0.000',
  totalNet: '0.000',
  taxes: [],
  totalTax: '0.000',
  fixedTaxes: [],
  total: '0.000',
  withholdings: [],
  amountDue: '0.000',
  amountPaid: '0.000',
  amountCredited: '0.000',
  payments: [],
};
const issued: InvoiceRow = { ...draft, number: 'FAC-1', status: 'issued', amountDue: '100.000' };
const input: InvoiceInput = {
  customerId: 'k1',
  establishmentId: 'e1',
  supplyDate: null,
  paymentTermsDays: null,
  customerReference: null,
  notesPrinted: null,
  notesInternal: null,
  discountAmount: null,
  documentTaxComponentIds: [],
  lines: [],
};
const payment = {
  date: '2026-09-16',
  amount: '40.000',
  method: 'cash',
  reference: null,
  notes: null,
} as const;

describe('InvoicesFacade', () => {
  const api = {
    options: vi.fn(),
    invoices: vi.fn(),
    invoice: vi.fn(),
    create: vi.fn(),
    revise: vi.fn(),
    issue: vi.fn(),
    cancel: vi.fn(),
    creditNote: vi.fn(),
    recordPayment: vi.fn(),
    deletePayment: vi.fn(),
    pdfUrl: vi.fn(),
  };
  let facade: InvoicesFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    api.options.mockResolvedValue(options);
    api.invoices.mockResolvedValue([draft]);
    api.invoice.mockResolvedValue(draft);
    TestBed.configureTestingModule({ providers: [{ provide: InvoicesApi, useValue: api }] });
    facade = TestBed.inject(InvoicesFacade);
  });

  it('reads the list with the options that name its drafts’ customers', async () => {
    await facade.loadList('c1');
    expect(facade.invoices()).toEqual([draft]);
    expect(facade.options()).toEqual(options);
  });

  it('reads the options alone for a new invoice', async () => {
    await facade.loadInvoice('c1', null);
    expect(api.invoice).not.toHaveBeenCalled();
    expect(facade.invoice()).toBeNull();
  });

  it('issues exactly what is on screen: the revision first, then the issue', async () => {
    const calls: string[] = [];
    api.revise.mockImplementation(async () => (calls.push('revise'), draft));
    api.issue.mockImplementation(async () => (calls.push('issue'), issued));

    expect(await facade.reviseAndIssue('c1', 'i1', input)).toEqual(issued);
    expect(calls).toEqual(['revise', 'issue']);
    expect(facade.invoice()).toEqual(issued);
  });

  it('does not issue when the revision is refused, and says why', async () => {
    api.revise.mockRejectedValue(new InvoicesRefused('invalid'));

    expect(await facade.reviseAndIssue('c1', 'i1', input)).toBeNull();
    expect(api.issue).not.toHaveBeenCalled();
    expect(facade.error()).toBe('invalid');
  });

  it('reads the invoice again after a payment is recorded or deleted, so its amount due is the API’s', async () => {
    api.invoice.mockResolvedValue(issued);
    expect(await facade.recordPayment('c1', 'i1', payment)).toBe(true);
    expect(api.recordPayment).toHaveBeenCalledWith('c1', 'i1', payment);
    expect(facade.invoice()).toEqual(issued);

    expect(await facade.deletePayment('c1', 'i1', 'y1')).toBe(true);
    expect(api.deletePayment).toHaveBeenCalledWith('c1', 'i1', 'y1');
    expect(api.invoice).toHaveBeenCalledTimes(2);
  });

  it('keeps the invoice as it was when a payment is refused', async () => {
    api.recordPayment.mockRejectedValue(new InvoicesRefused('invalid'));
    expect(await facade.recordPayment('c1', 'i1', payment)).toBe(false);
    expect(api.invoice).not.toHaveBeenCalled();
    expect(facade.error()).toBe('invalid');
  });

  it('answers the credit note it drafted, which becomes the document on screen', async () => {
    const credit = { ...draft, id: 'cn1', type: 'credit_note' as const, correctsInvoiceId: 'i1' };
    api.creditNote.mockResolvedValue(credit);
    expect(await facade.creditNote('c1', 'i1')).toEqual(credit);
    expect(facade.invoice()).toEqual(credit);
  });

  it('names a network failure apart from a refusal', async () => {
    api.invoices.mockRejectedValue(new Error('offline'));
    await facade.loadList('c1');
    expect(facade.error()).toBe('network');
  });
});
