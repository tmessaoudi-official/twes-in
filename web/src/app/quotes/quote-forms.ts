// SPDX-License-Identifier: AGPL-3.0-or-later

import type { InvoiceOptions } from '../invoices/invoices-types';
import type { LinesArray } from '../invoices/invoice-forms';
import type { FormDescriptor, FormField, FormValues } from '../shared/form/form-types';
import { filterValues, idValues, rangeParams } from '../shared/list/list-filters';
import type { ListDescriptor, ListQuery } from '../shared/list/list-types';
import {
  QUOTE_STATUS_TONES,
  QUOTE_STATUSES,
  type QuoteInput,
  type QuoteRow,
  type QuoteSearch,
  type QuoteShownStatus,
  type QuoteSortKey,
} from './quotes-types';

const FIELDS = 'quotes.fields';
/** An amount as the API takes it: at most twelve digits, then at most three decimals. */
const AMOUNT_PATTERN = '(0|[1-9][0-9]{0,11})([.][0-9]{1,3})?';
/** The longest refusal reason the API keeps. */
export const REFUSAL_REASON_MAX = 500;

/** What a list or a header shows: « Expiré » for a sent quote the API says is past its validity day. */
export function shownStatus(quote: QuoteRow): QuoteShownStatus {
  return quote.status === 'sent' && quote.expired ? 'expired' : quote.status;
}

const SORT_KEYS: Readonly<Record<string, QuoteSortKey>> = {
  number: 'number',
  customer: 'customer',
  issueDate: 'issueDate',
  validUntil: 'validUntil',
  status: 'status',
};

/** What the API is asked for the page of quotes the list shows. */
export function quoteSearch(query: ListQuery): QuoteSearch {
  const status = QUOTE_STATUSES.filter((known) =>
    filterValues(query.filters['status']).includes(known),
  );
  const key = query.sort === null ? undefined : SORT_KEYS[query.sort.column];
  return {
    page: query.pageIndex + 1,
    itemsPerPage: query.pageSize,
    q: query.query,
    status,
    customerIds: idValues(query.filters['customer']),
    intervals: rangeParams(query.filters, [{ id: 'issueDate', kind: 'day' }]),
    order:
      query.sort === null || key === undefined ? null : { key, direction: query.sort.direction },
  };
}

/** A quote as the list shows it: with its customer's name and the status shown for it. */
export type QuoteListRow = QuoteRow & { customer: string; shown: QuoteShownStatus };

/** A sent quote names the customer it was sent to; a draft names the customer as the company has it today. */
export function quoteListRows(quotes: readonly QuoteRow[]): QuoteListRow[] {
  return quotes.map((quote) => ({
    ...quote,
    customer: quote.recordedCustomerName ?? quote.customerName,
    shown: shownStatus(quote),
  }));
}

export const QUOTES_LIST: ListDescriptor<QuoteListRow> = {
  id: 'quotes',
  rowId: (row) => row.id,
  link: (row) => ['/quotes', row.id],
  pageSizes: [25, 50, 100],
  defaultSort: { column: 'issueDate', direction: 'desc' },
  columns: [
    {
      id: 'number',
      label: `${FIELDS}.number`,
      value: (row) => row.number ?? '',
      sortable: true,
      filterable: true,
      hideable: false,
      width: 170,
    },
    {
      id: 'customer',
      label: `${FIELDS}.customer`,
      value: (row) => row.customer,
      sortable: true,
      filterable: true,
      hideable: false,
    },
    {
      id: 'issueDate',
      label: `${FIELDS}.issueDate`,
      value: (row) => row.issueDate ?? '',
      sortable: true,
      width: 130,
    },
    {
      id: 'validUntil',
      label: `${FIELDS}.validUntil`,
      value: (row) => row.validUntil ?? '',
      shown: (row) => row.validUntil !== null,
      sortable: true,
      width: 130,
    },
    {
      id: 'total',
      label: `${FIELDS}.total`,
      value: (row) => row.total,
      align: 'end',
      width: 150,
    },
    {
      id: 'status',
      label: `${FIELDS}.status`,
      value: (row) => row.shown,
      sortable: true,
      width: 170,
    },
  ],
  ranges: [{ id: 'issueDate', kind: 'day', label: `${FIELDS}.issueDate` }],
  picks: [{ id: 'customer', label: `${FIELDS}.customer` }],
  filters: [
    {
      id: 'status',
      label: `${FIELDS}.status`,
      multiple: true,
      value: (row) => row.status,
      options: QUOTE_STATUSES.map((status) => ({
        value: status,
        label: `quotes.statuses.${status}`,
        tone: QUOTE_STATUS_TONES[status],
      })),
    },
  ],
};

const section = (id: string, fields: FormField[]): FormDescriptor['sections'][number] => ({
  id,
  title: `quotes.sections.${id}`,
  fields,
});

/**
 * The quote's header: its establishment among what the company offers, keeping the one a quote already names when the
 * company no longer offers it, then the customer's reference, a discount and what is printed. The customer is a picker
 * beside this form, as on an invoice. The API checks it all again.
 */
export function quoteForm(
  options: InvoiceOptions,
  current: QuoteRow | null = null,
): FormDescriptor {
  const establishments = options.establishments.map((establishment) => ({
    value: establishment.id,
    label: `${establishment.code} · ${establishment.name}`,
  }));
  const establishmentId = current?.establishmentId ?? null;
  if (
    establishmentId !== null &&
    !establishments.some((option) => option.value === establishmentId)
  ) {
    establishments.push({ value: establishmentId, label: establishmentId });
  }

  return {
    id: 'quote',
    sections: [
      section('parties', [
        {
          id: 'establishmentId',
          label: `${FIELDS}.establishmentId`,
          kind: 'select',
          required: true,
          options: establishments,
        },
        {
          id: 'customerReference',
          label: `${FIELDS}.customerReference`,
          kind: 'text',
          maxLength: 64,
          hint: 'quotes.form.reference_hint',
        },
      ]),
      section('terms', [
        {
          id: 'discountAmount',
          label: `${FIELDS}.discountAmount`,
          kind: 'decimal',
          maxLength: 16,
          pattern: AMOUNT_PATTERN,
          hint: 'quotes.form.discount_hint',
        },
      ]),
      section('notes', [
        {
          id: 'notesPrinted',
          label: `${FIELDS}.notesPrinted`,
          kind: 'textarea',
          maxLength: 5000,
          span: 2,
          hint: 'quotes.form.printed_hint',
        },
        {
          id: 'notesInternal',
          label: `${FIELDS}.notesInternal`,
          kind: 'textarea',
          maxLength: 5000,
          span: 2,
          hint: 'quotes.form.internal_hint',
        },
      ]),
    ],
  };
}

/** Each header field at the quote's value; a new quote starts at the company's default establishment. */
export function quoteValues(row: QuoteRow | null, options: InvoiceOptions): FormValues {
  const establishment =
    options.establishments.find((each) => each.isDefault) ?? options.establishments[0];
  return {
    establishmentId: row?.establishmentId ?? establishment?.id ?? '',
    customerReference: row?.customerReference ?? '',
    discountAmount: row?.discountAmount ?? '',
    notesPrinted: row?.notesPrinted ?? '',
    notesInternal: row?.notesInternal ?? '',
  };
}

const text = (value: unknown): string | null => {
  const trimmed = String(value ?? '').trim();
  return trimmed === '' ? null : trimmed;
};

/** The header and the lines as the API takes them: trimmed, an empty field as no value. */
export function quoteInput(values: FormValues, lines: LinesArray, customerId: string): QuoteInput {
  return {
    customerId,
    establishmentId: text(values['establishmentId']),
    customerReference: text(values['customerReference']),
    notesPrinted: text(values['notesPrinted']),
    notesInternal: text(values['notesInternal']),
    discountAmount: text(values['discountAmount']),
    lines: lines.getRawValue().map((line) => ({
      productId: line.productId === '' ? null : line.productId,
      description: line.description.trim(),
      quantity: line.quantity.trim(),
      unitId: line.unitId,
      unitPriceNet: line.unitPriceNet.trim(),
      discountRate: line.discountRate.trim() === '' ? null : line.discountRate.trim(),
      taxComponentIds: [...line.taxComponentIds],
    })),
  };
}
