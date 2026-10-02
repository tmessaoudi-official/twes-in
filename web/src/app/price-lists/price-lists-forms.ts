// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ListDescriptor } from '../shared/list/list-types';
import { scopeOf, type PriceListRow } from './price-lists-types';

const FIELDS = 'price_lists.list';

/** The company's price lists: who each is for, when it applies and how many prices it holds. */
export const PRICE_LISTS_LIST: ListDescriptor<PriceListRow> = {
  id: 'price-lists',
  rowId: (row) => row.id,
  pageSizes: [25, 50, 100],
  defaultSort: { column: 'name', direction: 'asc' },
  columns: [
    {
      id: 'name',
      label: `${FIELDS}.name`,
      value: (row) => row.name,
      sortable: true,
      filterable: true,
      hideable: false,
    },
    { id: 'scope', label: `${FIELDS}.scope`, value: (row) => scopeOf(row), sortable: true },
    { id: 'validity', label: `${FIELDS}.validity`, value: (row) => row.validFrom ?? '' },
    {
      id: 'itemCount',
      label: `${FIELDS}.item_count`,
      value: (row) => row.itemCount,
      sortable: true,
      align: 'end',
      width: 140,
    },
    {
      id: 'state',
      label: `${FIELDS}.state`,
      value: (row) => (row.isActive ? 'active' : 'inactive'),
      sortable: true,
      width: 140,
    },
  ],
};
