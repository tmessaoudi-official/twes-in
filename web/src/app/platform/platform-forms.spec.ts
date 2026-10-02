// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ListQuery } from '../shared/list/list-types';
import { accountSearch, companySearch } from './platform-forms';

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
          filters: { status: 'pending', country: 'TN' },
          sort: { column: 'created', direction: 'desc' },
          pageIndex: 2,
          pageSize: 50,
        }),
      ),
    ).toEqual({
      page: 3,
      itemsPerPage: 50,
      q: 'nadia@',
      status: 'pending',
      countryCode: 'TN',
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
    ).toMatchObject({ status: null, countryCode: null, order: null });
  });

  it('turns the accounts state filter into what the API filters by', () => {
    expect(accountSearch(query({ filters: { state: 'active' } }))).toMatchObject({
      active: true,
      platformOperator: null,
    });
    expect(accountSearch(query({ filters: { state: 'inactive' } }))).toMatchObject({
      active: false,
      platformOperator: null,
    });
    expect(accountSearch(query({ filters: { state: 'operator' } }))).toMatchObject({
      active: null,
      platformOperator: true,
    });
    expect(accountSearch(query({}))).toMatchObject({ active: null, platformOperator: null });
  });

  it('sorts accounts by the key the API names for the column', () => {
    expect(accountSearch(query({ sort: { column: 'state', direction: 'asc' } })).order).toEqual({
      key: 'active',
      direction: 'asc',
    });
  });
});
