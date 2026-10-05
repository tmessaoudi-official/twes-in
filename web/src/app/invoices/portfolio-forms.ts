// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ListDescriptor, ListQuery } from '../shared/list/list-types';
import { INSTRUMENT_STATUS_TONES } from './instruments-types';
import {
  PORTFOLIO_SCOPES,
  type PortfolioRow,
  type PortfolioSearch,
  type PortfolioSortKey,
} from './portfolio-types';

const FIELDS = 'invoices.portfolio.fields';

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
    { id: 'number', label: `${FIELDS}.number`, value: (row) => row.number ?? '', width: 150 },
    { id: 'bank', label: `${FIELDS}.bank`, value: (row) => row.bank ?? '', width: 150 },
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
  filters: [
    {
      id: 'status',
      label: `${FIELDS}.status`,
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
  const chosen = query.filters['status'];
  const status = PORTFOLIO_SCOPES.find((scope) => scope === chosen) ?? null;
  const key = query.sort === null ? undefined : SORT_KEYS[query.sort.column];
  return {
    page: query.pageIndex + 1,
    itemsPerPage: query.pageSize,
    status,
    order:
      query.sort === null || key === undefined ? null : { key, direction: query.sort.direction },
  };
}
