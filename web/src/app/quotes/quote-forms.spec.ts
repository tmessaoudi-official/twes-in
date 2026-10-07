// SPDX-License-Identifier: AGPL-3.0-or-later

import { linesArray } from '../invoices/invoice-forms';
import type { InvoiceOptions } from '../invoices/invoices-types';
import type { ListQuery } from '../shared/list/list-types';
import { quoteInput, quoteListRows, quoteSearch, quoteValues, shownStatus } from './quote-forms';
import type { QuoteRow } from './quotes-types';

const options: InvoiceOptions = {
  currency: 'TND',
  currencyScale: 3,
  establishments: [
    { id: 'e0', code: '001', name: 'Agence', isDefault: false },
    { id: 'e1', code: '000', name: 'Siège', isDefault: true },
  ],
  units: [{ id: 'u1', code: 'C62', name: 'Unité', decimals: 0 }],
  taxes: [],
};

function quote(changes: Partial<QuoteRow> = {}): QuoteRow {
  return {
    id: 'q1',
    number: 'DEV-2026-10-00001',
    status: 'sent',
    expired: false,
    customerId: 'k1',
    recordedCustomerName: 'Acme SARL',
    customerName: 'Acme',
    establishmentId: null,
    issueDate: '2026-10-01',
    validUntil: '2026-10-31',
    answeredOn: null,
    refusalReason: null,
    invoiceId: null,
    attachmentCount: 0,
    customerReference: null,
    notesPrinted: null,
    notesInternal: null,
    discountAmount: null,
    lines: [],
    subtotalNet: '0',
    documentDiscount: '0',
    taxes: [],
    totalTax: '0',
    total: '0',
    ...changes,
  };
}

describe('quote forms', () => {
  it('shows a sent quote the API says is past its day as expired, and nothing else', () => {
    expect(shownStatus(quote({ expired: true }))).toBe('expired');
    expect(shownStatus(quote())).toBe('sent');
    // The API only ever marks a sent quote expired; an accepted one stays accepted whatever its day.
    expect(shownStatus(quote({ status: 'accepted', expired: true }))).toBe('accepted');
  });

  it('lists a sent quote under the customer it recorded and a draft under today’s name', () => {
    const [sent, draft] = quoteListRows([
      quote(),
      quote({ status: 'draft', recordedCustomerName: null, number: null }),
    ]);
    expect(sent?.customer).toBe('Acme SARL');
    expect(draft?.customer).toBe('Acme');
  });

  it('asks the API for the statuses it knows, never « expired », with the customers, days and order', () => {
    const query: ListQuery = {
      pageIndex: 1,
      pageSize: 50,
      query: 'tour',
      sort: { column: 'validUntil', direction: 'asc' },
      filters: {
        status: 'sent,expired,accepted',
        customer: '0192c3a4-0000-7000-8000-000000000001',
        'issueDate.from': '2026-10-01',
      },
    };
    const search = quoteSearch(query);
    expect(search.page).toBe(2);
    expect(search.status).toEqual(['sent', 'accepted']);
    expect(search.customerIds).toEqual(['0192c3a4-0000-7000-8000-000000000001']);
    expect(search.intervals).toEqual({ 'issueDate.from': '2026-10-01' });
    expect(search.order).toEqual({ key: 'validUntil', direction: 'asc' });
  });

  it('starts a new quote at the default establishment and keeps a saved one’s', () => {
    expect(quoteValues(null, options)['establishmentId']).toBe('e1');
    expect(quoteValues(quote({ establishmentId: 'e0' }), options)['establishmentId']).toBe('e0');
  });

  it('sends the header trimmed, an empty field as none, and lines without what a quote never names', () => {
    const lines = linesArray([], options, null);
    lines.at(0).patchValue({
      description: ' Pose ',
      quantity: '2 ',
      unitPriceNet: '40',
      discountRate: '',
      lotCode: 'L-1',
      sourceDeliveryNoteLineId: 'x',
    });
    const input = quoteInput(
      {
        establishmentId: 'e1',
        customerReference: '  ',
        discountAmount: '10',
        notesPrinted: '',
        notesInternal: ' interne ',
      },
      lines,
      'k1',
    );
    expect(input).toEqual({
      customerId: 'k1',
      establishmentId: 'e1',
      customerReference: null,
      notesPrinted: null,
      notesInternal: 'interne',
      discountAmount: '10',
      lines: [
        {
          productId: null,
          description: 'Pose',
          quantity: '2',
          unitId: 'u1',
          unitPriceNet: '40',
          discountRate: null,
          taxComponentIds: [],
        },
      ],
    });
  });
});
