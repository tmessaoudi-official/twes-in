// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import type { InvoiceOptions } from '../invoices/invoices-types';
import type { FieldRefusal } from '../shared/form/field-refusals';
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
  version: 1,
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
  receivedBy: null,
  receiverRule: 'optional',
  invoiceId: null,
  deposits: [],
  deliveryNotes: [],
  delivery: 'none',
  deliveries: [],
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
    addToInvoice: vi.fn(),
    draftsOf: vi.fn(),
    attach: vi.fn(),
    detach: vi.fn(),
    restoreAttachment: vi.fn(),
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

  it("adds a quote to a draft as a step, and reads the customer's drafts as none when they cannot be read", async () => {
    await facade.loadQuote('c1', 'q1');
    api.addToInvoice.mockResolvedValue({ ...sent, status: 'accepted', invoiceId: 'd1' });
    expect((await facade.addToInvoice('c1', 'q1', 'd1'))?.invoiceId).toBe('d1');
    expect(api.addToInvoice).toHaveBeenCalledWith('c1', 'q1', 'd1');
    expect(facade.quote()?.invoiceId).toBe('d1');

    const drafts = [{ id: 'd1', total: '10.000', lineCount: 1, customerReference: null }];
    api.draftsOf.mockResolvedValue(drafts);
    expect(await facade.draftsOf('c1', 'k1', 'e1')).toEqual(drafts);
    api.draftsOf.mockRejectedValue(new QuotesRefused('network'));
    expect(await facade.draftsOf('c1', 'k1', 'e1')).toBeNull();
    expect(facade.error()).toBe('network');
  });

  it('puts a file back, reading the quote again only while it is still the one open', async () => {
    await facade.loadQuote('c1', 'q1');
    api.attachments.mockClear();
    api.restoreAttachment.mockResolvedValue(undefined);

    expect(await facade.restoreAttachment('c1', 'q1', 'a1')).toBeNull();
    expect(api.restoreAttachment).toHaveBeenCalledWith('c1', 'q1', 'a1');
    expect(api.attachments).toHaveBeenCalledWith('c1', 'q1');

    // The toast outlived its page: another quote is open now, and it keeps what it shows.
    api.attachments.mockClear();
    expect(await facade.restoreAttachment('c1', 'q7', 'a9')).toBeNull();
    expect(api.attachments).not.toHaveBeenCalled();
    expect(facade.quote()).toEqual(sent);

    // A refusal is the toast's to say, never written on the page open now.
    api.restoreAttachment.mockRejectedValue(new QuotesRefused('files_full'));
    expect(await facade.restoreAttachment('c1', 'q1', 'a1')).toBe('files_full');
    expect(facade.error()).toBeNull();
  });

  it('keeps the fields a save was refused on until the next step, which the form says under each', async () => {
    const refused = [{ field: 'lines[0].quantity', reason: 'invalid_quantity', params: {} }];
    api.revise.mockRejectedValueOnce(new QuotesRefused('invalid', refused));

    expect(await facade.revise('c1', 'q1', input, 3)).toBeNull();
    expect(facade.refusals()).toEqual(refused);

    api.revise.mockResolvedValueOnce({ ...sent, status: 'draft' });
    await facade.revise('c1', 'q1', input, 3);
    expect(facade.refusals()).toEqual([]);
  });

  it('saves what is shown before marking it sent, so a quote is numbered with what was seen', async () => {
    const order: string[] = [];
    api.revise.mockImplementation(async () => (order.push('revise'), { ...sent, status: 'draft' }));
    api.send.mockImplementation(async () => (order.push('send'), sent));

    expect(await facade.reviseAndSend('c1', 'q1', input, 3, () => order.push('revised'))).toEqual(
      sent,
    );

    expect(api.revise).toHaveBeenCalledWith('c1', 'q1', input, 3);
    // The form is told the save was kept before sending answers, whatever sending then says.
    expect(order).toEqual(['revise', 'revised', 'send']);
  });

  it('attaches the signed copy once the yes is kept, and reads the files again', async () => {
    const accepted = { ...sent, status: 'accepted' as const };
    api.accept.mockResolvedValue(accepted);
    api.attach.mockResolvedValue({ id: 'a1' });
    api.attachments.mockResolvedValue([{ id: 'a1', name: 'signé.pdf' }]);
    api.quote.mockResolvedValue({ ...accepted, attachmentCount: 1 });

    expect(await facade.accept('c1', 'q1', { answeredOn: '' }, signed)).toEqual({ kept: accepted });

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
    expect(await facade.accept('c1', 'q1', { answeredOn: '' }, signed)).toEqual({ kept: accepted });
    expect(facade.quote()?.status).toBe('accepted');
    expect(facade.error()).toBe('file_refused');
  });

  it('hands the dialog an answer’s refused fields, keeping them off the screen behind it, and attaches nothing', async () => {
    const day: FieldRefusal = { field: 'answeredOn', reason: 'after_today', params: {} };
    api.accept.mockRejectedValueOnce(new QuotesRefused('invalid', [day]));
    expect(await facade.accept('c1', 'q1', { answeredOn: '2099-01-01' }, signed)).toEqual({
      refused: [day],
    });
    expect(api.attach).not.toHaveBeenCalled();
    expect(facade.error()).toBeNull();
    expect(facade.refusals()).toEqual([]);
  });

  it('answers a failed pick with nothing and says why', async () => {
    api.pickProducts.mockRejectedValue(new QuotesRefused('not_found'));
    expect(await facade.pickProducts('c1', { words: 'tour' })).toEqual([]);
    expect(facade.error()).toBe('not_found');
  });
});
