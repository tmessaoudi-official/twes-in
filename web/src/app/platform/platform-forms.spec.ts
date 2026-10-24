// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ListQuery } from '../shared/list/list-types';
import { ACCOUNTS_LIST, accountSearch, COMPANIES_LIST, companySearch } from './platform-forms';

const query = (over: Partial<ListQuery>): ListQuery => ({
  query: '',
  filters: {},
  sort: null,
  pageIndex: 0,
  pageSize: 25,
  ...over,
});

describe('the platform lists', () => {
  it('asks the API for the page, the words, the filters and the order the companies list shows', () => {
    expect(
      companySearch(
        query({
          query: 'nadia@',
          filters: { status: 'pending,suspended', country: 'TN' },
          sort: { column: 'created', direction: 'desc' },
          pageIndex: 2,
          pageSize: 50,
        }),
      ),
    ).toEqual({
      page: 3,
      itemsPerPage: 50,
      q: 'nadia@',
      statuses: ['pending', 'suspended'],
      countryCodes: ['TN'],
      order: { key: 'createdAt', direction: 'desc' },
    });
  });

  it('ignores a status, a country or a column the API does not know', () => {
    expect(
      companySearch(
        query({
          filters: { status: 'gone', country: 'ZZ' },
          sort: { column: 'owners', direction: 'asc' },
        }),
      ),
    ).toMatchObject({ statuses: [], countryCodes: [], order: null });
  });

  it('combines the accounts state and role facets into what the API filters by', () => {
    expect(accountSearch(query({ filters: { state: 'active' } }))).toMatchObject({
      active: true,
      platformOperator: null,
    });
    expect(accountSearch(query({ filters: { state: 'inactive' } }))).toMatchObject({
      active: false,
      platformOperator: null,
    });
    // A deactivated operator is found: the two facets combine rather than exclude each other.
    expect(
      accountSearch(query({ filters: { state: 'inactive', role: 'operator' } })),
    ).toMatchObject({ active: false, platformOperator: true });
    expect(accountSearch(query({ filters: { role: 'member' } }))).toMatchObject({
      active: null,
      platformOperator: false,
    });
    // Both values of a facet, or none, are every account: « Tous ».
    expect(
      accountSearch(query({ filters: { state: 'active,inactive', role: 'operator,member' } })),
    ).toMatchObject({ active: null, platformOperator: null });
    expect(accountSearch(query({}))).toMatchObject({ active: null, platformOperator: null });
  });

  it('reads an address or a saved view written with the old single state filter', () => {
    expect(accountSearch(query({ filters: { state: 'operator' } }))).toMatchObject({
      active: null,
      platformOperator: true,
    });
  });

  it('offers the state and the role as two facets, each taking several values', () => {
    expect(
      ACCOUNTS_LIST.filters?.map((filter) => ({
        id: filter.id,
        multiple: filter.multiple === true,
        options: filter.options?.map((option) => option.value),
      })),
    ).toEqual([
      { id: 'state', multiple: true, options: ['active', 'inactive'] },
      { id: 'role', multiple: true, options: ['operator', 'member'] },
    ]);
  });

  it('sorts accounts by the key the API names for the column', () => {
    expect(accountSearch(query({ sort: { column: 'state', direction: 'asc' } })).order).toEqual({
      key: 'active',
      direction: 'asc',
    });
  });

  // Both APIs read their words in a name (and an address the list does not show): each list draws its search.
  it('offers a search on the companies and the accounts lists', () => {
    for (const list of [COMPANIES_LIST, ACCOUNTS_LIST]) {
      expect(
        list.columns.filter((column) => column.filterable).map((column) => column.id),
        list.id,
      ).toEqual(['name']);
    }
  });
});
