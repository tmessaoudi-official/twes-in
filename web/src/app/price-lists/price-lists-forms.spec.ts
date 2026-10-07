// SPDX-License-Identifier: AGPL-3.0-or-later

import { applyFilters } from '../shared/list/list-view';
import { PRICE_LISTS_LIST } from './price-lists-forms';
import type { PriceListRow } from './price-lists-types';

const list = (id: string, isActive: boolean): PriceListRow => ({
  id,
  name: `Tarif ${id}`,
  customerGroupId: null,
  customerId: null,
  validFrom: null,
  validTo: null,
  isActive,
  itemCount: 0,
  items: null,
});

describe('the price lists list', () => {
  it('narrows by whether a price list is still applied', () => {
    const rows = [list('a', true), list('b', false), list('c', true)];

    expect(
      applyFilters(rows, PRICE_LISTS_LIST.filters ?? [], { state: 'inactive' }).map(
        (row) => row.id,
      ),
    ).toEqual(['b']);
  });
});
