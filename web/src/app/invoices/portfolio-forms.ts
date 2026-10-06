// SPDX-License-Identifier: AGPL-3.0-or-later

import { filterValues, idValues, rangeParams } from '../shared/list/list-filters';
import type { ListDescriptor, ListQuery } from '../shared/list/list-types';
import { INSTRUMENT_KINDS, INSTRUMENT_STATUS_TONES } from './instruments-types';
import {
  PORTFOLIO_SCOPES,
  type PortfolioRow,
  type PortfolioSearch,
  type PortfolioSortKey,
} from './portfolio-types';

const FIELDS = 'invoices.portfolio.fields';

/** The intervals the « Filtres » panel offers, in the order it draws them. */
const PORTFOLIO_INTERVALS: readonly { id: string; kind: 'day' | 'amount'; label: string }[] = [
  { id: 'dueOn', kind: 'day', label: `${FIELDS}.dueOn` },
  { id: 'amount', kind: 'amount', label: `${FIELDS}.amount` },
];

/**
 * The company's cheques and traites, the nearest due day first. Nothing chosen lists every one; the chips narrow to
 * what is still open or to one status. The invoice number opens the invoice, where each one is deposited
 * and cashed.
 */
export const PORTFOLIO_LIST: ListDescriptor<PortfolioRow> = {
  id: 'instrument-portfolio',
  rowId: (row) => row.id,
  link: (row) => ['/invoices', row.invoiceId],
  linkColumn: 'invoice',
  pageSizes: [25, 50, 100],
  defaultSort: { column: 'dueOn', direction: 'asc' },
  columns: [
    {
      id: 'dueOn',
      label: `${FIELDS}.dueOn`,
      value: (row) => row.dueOn,
      sortable: true,
      hideable: false,
      width: 130,
    },
    {
      id: 'invoice',
      label: `${FIELDS}.invoice`,
      value: (row) => row.invoiceNumber ?? '',
      hideable: false,
      width: 190,
    },
    { id: 'customer', label: `${FIELDS}.customer`, value: (row) => row.customerName },
    { id: 'kind', label: `${FIELDS}.kind`, value: (row) => row.kind, width: 110 },
    // The instrument's own number and bank wait until placed: with them the list is wider than a 1280 px window leaves.
    {
      id: 'number',
      label: `${FIELDS}.number`,
      value: (row) => row.number ?? '',
      width: 150,
      defaultHidden: true,
    },
    {
      id: 'bank',
      label: `${FIELDS}.bank`,
      value: (row) => row.bank ?? '',
      width: 150,
      defaultHidden: true,
    },
    {
      id: 'amount',
      label: `${FIELDS}.amount`,
      value: (row) => Number(row.amount),
      sortable: true,
      align: 'end',
      width: 160,
    },
    {
      id: 'status',
      label: `${FIELDS}.status`,
      value: (row) => row.status,
      sortable: true,
      width: 150,
    },
  ],
  ranges: PORTFOLIO_INTERVALS.map(({ id, kind, label }) => ({ id, kind, label })),
  picks: [{ id: 'customer', label: `${FIELDS}.customer` }],
  filters: [
    {
      id: 'kind',
      label: `${FIELDS}.kind`,
      multiple: true,
      value: (row) => row.kind,
      options: INSTRUMENT_KINDS.map((kind) => ({
        value: kind,
        label: `invoices.instruments.kinds.${kind}`,
      })),
    },
    {
      id: 'status',
      label: `${FIELDS}.status`,
      multiple: true,
      value: (row) => row.status,
      options: PORTFOLIO_SCOPES.map((scope) => ({
        value: scope,
        label: `invoices.portfolio.scopes.${scope}`,
        ...(scope === 'open' ? {} : { tone: INSTRUMENT_STATUS_TONES[scope] }),
      })),
    },
  ],
};

const SORT_KEYS: Readonly<Record<string, PortfolioSortKey>> = {
  dueOn: 'dueOn',
  amount: 'amount',
  status: 'status',
};

/** What the API is asked for the page of the portfolio the list shows. */
export function portfolioSearch(query: ListQuery): PortfolioSearch {
  const status = PORTFOLIO_SCOPES.filter((scope) =>
    filterValues(query.filters['status']).includes(scope),
  );
  const kinds = INSTRUMENT_KINDS.filter((kind) =>
    filterValues(query.filters['kind']).includes(kind),
  );
  const intervals = rangeParams(query.filters, PORTFOLIO_INTERVALS);
  const key = query.sort === null ? undefined : SORT_KEYS[query.sort.column];
  return {
    page: query.pageIndex + 1,
    itemsPerPage: query.pageSize,
    status,
    kinds,
    customerIds: idValues(query.filters['customer']),
    intervals,
    order:
      query.sort === null || key === undefined ? null : { key, direction: query.sort.direction },
  };
}
