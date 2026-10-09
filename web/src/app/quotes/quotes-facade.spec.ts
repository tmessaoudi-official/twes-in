// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import type { InvoiceOptions } from '../invoices/invoices-types';
import { QuotesApi, QuotesRefused } from './quotes-api';
import { QuotesFacade } from './quotes-facade';
import type { QuoteInput, QuoteRow } from './quotes-types';

const options: InvoiceOptions = {
  currency: 'TND',
  currencyScale: 3,
  operationCategory: false,
  establishments: [],
  units: [],
  taxes: [],
};
const sent: QuoteRow = {
  id: 'q1',
  number: 'DEV-2026-10-00001',
  status: 'sent',
  expired: false,
  customerId: 'k1',
  recordedCustomerName: 'Acme',
  customerName: 'Acme',
  establishmentId: null,
  issueDate: '2026-10-01',
  validUntil: '2026-10-31',
  answeredOn: null,
  refusalReason: null,
  invoiceId: null,
  deposits: [],
  attachmentCount: 0,
  customerReference: null,
  notesPrinted: null,
  notesInternal: null,
  discountAmount: null,
  lines: [],
  subtotalNet: '0',
  documentDiscount: '0',
  savings: '0.000',
  taxes: [],
  totalTax: '0',
  total: '0',
};
const input: QuoteInput = {
  customerId: 'k1',
  establishmentId: null,
  customerReference: null,
  notesPrinted: null,
  notesInternal: null,
  discountAmount: null,
  lines: [],
};
const signed = new File(['%PDF'], 'signé.pdf', { type: 'application/pdf' });

describe('QuotesFacade', () => {
  const api = {
    options: vi.fn(),
    quote: vi.fn(),
    quotes: vi.fn(),
    statusCounts: vi.fn(),
    attachments: vi.fn(),
    create: vi.fn(),
    revise: vi.fn(),
    send: vi.fn(),
    accept: vi.fn(),
    refuse: vi.fn(),
    cancel: vi.fn(),
    invoice: vi.fn(),
    attach: vi.fn(),
    detach: vi.fn(),
    pickCustomers: vi.fn(),
    pickProducts: vi.fn(),
  };
  let facade: QuotesFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    api.options.mockResolvedValue(options);
    api.quote.mockResolvedValue(sent);
    api.attachments.mockResolvedValue([]);
    TestBed.configureTestingModule({ providers: [{ provide: QuotesApi, useValue: api }] });
    facade = TestBed.inject(QuotesFacade);
  });

  it('reads the options alone for a new quote, and the quote and its files for a saved one', async () => {
    await facade.loadQuote('c1', null);
    expect(api.quote).not.toHaveBeenCalled();
    expect(facade.quote()).toBeNull();

    await facade.loadQuote('c1', 'q1');
    expect(api.attachments).toHaveBeenCalledWith('c1', 'q1');
    expect(facade.quote()).toEqual(sent);
  });

  it('saves what is shown before marking it sent, so a quote is numbered with what was seen', async () => {
    const order: string[] = [];
    api.revise.mockImplementation(async () => (order.push('revise'), { ...sent, status: 'draft' }));
    api.send.mockImplementation(async () => (order.push('send'), sent));

    expect(await facade.reviseAndSend('c1', 'q1', input)).toEqual(sent);

    expect(order).toEqual(['revise', 'send']);
  });

  it('attaches the signed copy once the yes is kept, and reads the files again', async () => {
    const accepted = { ...sent, status: 'accepted' as const };
    api.accept.mockResolvedValue(accepted);
    api.attach.mockResolvedValue({ id: 'a1' });
    api.attachments.mockResolvedValue([{ id: 'a1', name: 'signé.pdf' }]);
    api.quote.mockResolvedValue({ ...accepted, attachmentCount: 1 });

    expect(await facade.accept('c1', 'q1', { answeredOn: '' }, signed)).toEqual(accepted);

    expect(api.attach).toHaveBeenCalledWith('c1', 'q1', signed);
    expect(facade.attachments()).toHaveLength(1);
    expect(facade.quote()?.attachmentCount).toBe(1);
  });

  it('attaches nothing when the yes is refused, and keeps the yes when only the file is', async () => {
    api.accept.mockRejectedValueOnce(new QuotesRefused('conflict'));
    expect(await facade.accept('c1', 'q1', { answeredOn: '' }, signed)).toBeNull();
    expect(api.attach).not.toHaveBeenCalled();
    expect(facade.error()).toBe('conflict');

    const accepted = { ...sent, status: 'accepted' as const };
    api.accept.mockResolvedValue(accepted);
    api.attach.mockRejectedValue(new QuotesRefused('file_refused'));
    expect(await facade.accept('c1', 'q1', { answeredOn: '' }, signed)).toEqual(accepted);
    expect(facade.quote()?.status).toBe('accepted');
    expect(facade.error()).toBe('file_refused');
  });

  it('answers a failed pick with nothing and says why', async () => {
    api.pickProducts.mockRejectedValue(new QuotesRefused('not_found'));
    expect(await facade.pickProducts('c1', { words: 'tour' })).toEqual([]);
    expect(facade.error()).toBe('not_found');
  });
});
