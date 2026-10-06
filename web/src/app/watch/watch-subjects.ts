// SPDX-License-Identifier: AGPL-3.0-or-later

import type { IconName } from '../shared/icons/icons';
import type { ListColumn, ListDescriptor, RowAction } from '../shared/list/list-types';
import type { WatchRowView } from './watch-view';

/**
 * One subject of « À surveiller » as the screen draws it (docs/SPEC.md § 7, the subject pages): its icon on the
 * overview, and the table of what to act on, with the way into each row. Titles and hints are translated under
 * `watch.subjects.<kind>`; the API says which subjects exist, and this says how each looks.
 */
export interface WatchSubjectView {
  readonly icon: IconName;
  readonly list: ListDescriptor<WatchRowView>;
  /** The screen that holds the whole of what this subject picks from, linked beside its title. */
  readonly elsewhere?: { readonly link: string; readonly label: string };
}

const COLUMNS = 'watch.columns';

function cell(
  id: string,
  options: Pick<ListColumn<WatchRowView>, 'align' | 'width' | 'hideable'> = {},
): ListColumn<WatchRowView> {
  return { id, label: `${COLUMNS}.${id}`, value: (view) => view.cells[id] ?? '', ...options };
}

/** What a column of days says, as the row's own signed figure turned the way the column reads. */
function daysColumn(
  id: string,
  label: string,
  turn: (days: number) => number,
): ListColumn<WatchRowView> {
  return {
    id,
    label: `${COLUMNS}.${label}`,
    value: (view) => turn(Number(view.cells['days'] ?? 0)),
    align: 'end',
    width: 150,
  };
}

const PAGE_SIZES = [25, 50, 100];

/** The overdue invoices of one customer: the invoices list reads `status` and `q` from its address. */
const INVOICES_OF_CUSTOMER: RowAction<WatchRowView> = {
  id: 'see-invoices',
  label: 'watch.actions.see_invoices',
  labelParams: (view) => ({ name: String(view.cells['customer'] ?? '') }),
  icon: 'receipt_long',
  link: () => ['/invoices'],
  linkQuery: (view) => ({ status: 'overdue', q: String(view.cells['customer'] ?? '') }),
};

/** The invoice a cheque or traite was received against, where it is deposited and cashed. */
const OPEN_INVOICE: RowAction<WatchRowView> = {
  id: 'open-invoice',
  label: 'watch.actions.open_invoice',
  labelParams: (view) => ({ name: String(view.cells['invoice'] ?? '') }),
  icon: 'receipt_long',
  link: (view) => ['/invoices', view.row.subjectId],
  shown: (view) => view.row.subjectId !== null,
};

/** The product page, where its stock, its lots and its reorder points are. */
const OPEN_PRODUCT: RowAction<WatchRowView> = {
  id: 'open-product',
  label: 'watch.actions.open_product',
  labelParams: (view) => ({ name: String(view.cells['product'] ?? '') }),
  icon: 'inventory_2',
  link: (view) => ['/products', view.row.subjectId],
  shown: (view) => view.row.subjectId !== null,
};

/** The movements page, where a cost reader enters the cost of a receipt left « à compléter ». */
const ENTER_RECEIPT_COST: RowAction<WatchRowView> = {
  id: 'enter-cost',
  label: 'watch.actions.enter_cost',
  labelParams: (view) => ({ name: String(view.cells['product'] ?? '') }),
  icon: 'request_quote',
  link: () => ['/stock/movements'],
  linkQuery: (view) => receiptCostQuery(view),
  shown: (view) => view.row.subjectId !== null,
};

/** The receipt and what it received, which the movements page opens on and asks the cost of. */
function receiptCostQuery(view: WatchRowView): Record<string, string> {
  return {
    productId: String(view.row.params['productId'] ?? ''),
    costOf: view.row.subjectId ?? '',
  };
}

function productList(
  id: string,
  columns: ListColumn<WatchRowView>[],
): ListDescriptor<WatchRowView> {
  return {
    id: `watch-${id}`,
    rowId: (view) => view.id,
    pageSizes: PAGE_SIZES,
    link: (view) => ['/products', view.row.subjectId],
    linkColumn: 'product',
    columns: [cell('product', { hideable: false }), cell('reference', { width: 140 }), ...columns],
    actions: [OPEN_PRODUCT],
  };
}

/** Every subject the API can name, in the order the overview lists them when it names several. */
export const WATCH_SUBJECTS: Readonly<Record<string, WatchSubjectView>> = {
  'invoices.instruments_due': {
    icon: 'payments',
    list: {
      id: 'watch-instruments-due',
      rowId: (view) => view.id,
      pageSizes: PAGE_SIZES,
      link: (view) => ['/invoices', view.row.subjectId],
      linkColumn: 'invoice',
      columns: [
        cell('invoice', { hideable: false }),
        cell('customer'),
        cell('kind', { width: 110 }),
        cell('number', { width: 150 }),
        cell('bank', { width: 150 }),
        cell('amount', { align: 'end', width: 170 }),
        daysColumn('due_days', 'due_days', (days) => -days),
      ],
      actions: [OPEN_INVOICE],
    },
    elsewhere: { link: '/instruments', label: 'invoices.portfolio.open' },
  },
  'invoices.late_customer': {
    icon: 'schedule',
    list: {
      id: 'watch-late-customer',
      rowId: (view) => view.id,
      pageSizes: PAGE_SIZES,
      link: () => ['/invoices'],
      linkQuery: (view) => ({ status: 'overdue', q: String(view.cells['customer'] ?? '') }),
      linkColumn: 'customer',
      columns: [
        cell('customer', { hideable: false }),
        cell('invoices', { align: 'end', width: 110 }),
        cell('amount', { align: 'end', width: 170 }),
        daysColumn('late_days', 'late_days', (days) => days),
      ],
      actions: [INVOICES_OF_CUSTOMER],
    },
  },
  'invoices.unsold_products': {
    icon: 'sell',
    list: productList('unsold-products', [
      daysColumn('unsold_days', 'unsold_days', (days) => days),
    ]),
  },
  'stock.reorder_point': {
    icon: 'inventory_2',
    list: productList('reorder-point', [
      cell('establishment'),
      cell('onHand', { align: 'end', width: 130 }),
      cell('point', { align: 'end', width: 130 }),
    ]),
  },
  'stock.running_out': {
    icon: 'hourglass_top',
    list: productList('running-out', [
      cell('onHand', { align: 'end', width: 130 }),
      daysColumn('days_left', 'days_left', (days) => days),
    ]),
  },
  'stock.lot_expired': {
    icon: 'error',
    list: productList('lot-expired', [
      cell('lot', { width: 140 }),
      cell('quantity', { align: 'end', width: 120 }),
      cell('expiresOn', { width: 140 }),
      daysColumn('expired_days', 'expired_days', (days) => -days),
    ]),
  },
  'stock.receipt_cost_to_complete': {
    icon: 'request_quote',
    list: {
      id: 'watch-receipt-cost',
      rowId: (view) => view.id,
      pageSizes: PAGE_SIZES,
      link: () => ['/stock/movements'],
      linkQuery: (view) => receiptCostQuery(view),
      linkColumn: 'product',
      columns: [
        cell('product', { hideable: false }),
        cell('reference', { width: 140 }),
        cell('quantity', { align: 'end', width: 120 }),
        cell('location'),
        cell('receivedOn', { width: 140 }),
      ],
      actions: [ENTER_RECEIPT_COST],
    },
  },
  'stock.lot_expiring': {
    icon: 'event_note',
    list: productList('lot-expiring', [
      cell('lot', { width: 140 }),
      cell('quantity', { align: 'end', width: 120 }),
      cell('expiresOn', { width: 140 }),
      daysColumn('in_days', 'in_days', (days) => days),
    ]),
  },
};

/** How a subject looks, or undefined for a kind this screen does not know yet. */
export function subjectView(kind: string): WatchSubjectView | undefined {
  return Object.hasOwn(WATCH_SUBJECTS, kind) ? WATCH_SUBJECTS[kind] : undefined;
}
