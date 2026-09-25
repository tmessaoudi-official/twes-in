// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { InvoicesApi, InvoicesRefused } from './invoices-api';
import { InvoicesFacade } from './invoices-facade';
import type { InvoiceInput, InvoiceOptions, InvoiceRow } from './invoices-types';

const options: InvoiceOptions = {
  currency: 'TND',
  currencyScale: 3,
  establishments: [],
  units: [],
  taxes: [],
};
const draft: InvoiceRow = {
  id: 'i1',
  type: 'invoice',
  correctsInvoiceId: null,
  creditNoteReason: null,
  number: null,
  status: 'draft',
  customerId: 'k1',
  recordedCustomerName: null,
  customerName: 'Carthage',
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
  /** What a list asks for when nothing is typed or chosen. */
  const search = {
    page: 1,
    itemsPerPage: 25,
    q: '',
    status: null,
    documentType: null,
    customerId: null,
    order: null,
  } as const;

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
    summary: vi.fn(),
  };
  let facade: InvoicesFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    api.options.mockResolvedValue(options);
    api.invoices.mockResolvedValue({ rows: [draft], total: 31 });
    api.invoice.mockResolvedValue(draft);
    TestBed.configureTestingModule({ providers: [{ provide: InvoicesApi, useValue: api }] });
    facade = TestBed.inject(InvoicesFacade);
  });

  it('reads the home summary, and says why when it cannot', async () => {
    const summary = { today: '2026-09-21', outstanding: '10.000' };
    api.summary.mockResolvedValueOnce(summary);
    await facade.loadSummary('c1');
    expect(api.summary).toHaveBeenCalledWith('c1');
    expect(facade.summary()).toEqual(summary);

    api.summary.mockRejectedValueOnce(new InvoicesRefused('not_found'));
    await facade.loadSummary('c1');
    expect(facade.summary()).toBeNull();
    expect(facade.error()).toBe('not_found');
  });

  it('reads one page of documents, with how many the search found in all', async () => {
    await facade.loadListContext('c1');
    expect(facade.options()).toEqual(options);

    await facade.loadPage('c1', search);
    expect(api.invoices).toHaveBeenCalledWith('c1', search);
    expect(facade.invoices()).toEqual([draft]);
    expect(facade.total()).toBe(31);
  });

  it('shows the answer to the latest search, whatever order the answers come back in', async () => {
    // Typing sends one search per keystroke; an earlier answer arriving late must not replace a later one.
    let settleFirst = (): void => {
      throw new Error('the first search was answered before it was sent');
    };
    api.invoices
      .mockReturnValueOnce(
        new Promise((resolve) => {
          settleFirst = () => resolve({ rows: [draft], total: 1 });
        }),
      )
      .mockResolvedValueOnce({ rows: [], total: 0 });

    const stale = facade.loadPage('c1', { ...search, q: 'car' });
    const latest = facade.loadPage('c1', { ...search, q: 'carthage' });
    await latest;
    settleFirst();
    await stale;

    expect(facade.invoices()).toEqual([]);
    expect(facade.total()).toBe(0);
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
    expect(await facade.creditNote('c1', 'i1', 'Retour')).toEqual(credit);
    expect(api.creditNote).toHaveBeenCalledWith('c1', 'i1', 'Retour');
    expect(facade.invoice()).toEqual(credit);
  });

  it('names a network failure apart from a refusal', async () => {
    api.invoices.mockRejectedValue(new Error('offline'));
    await facade.loadPage('c1', search);
    expect(facade.error()).toBe('network');
  });
});
