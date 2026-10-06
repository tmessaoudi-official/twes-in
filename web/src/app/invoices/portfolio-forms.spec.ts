// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ListQuery } from '../shared/list/list-types';
import { PORTFOLIO_LIST, portfolioSearch } from './portfolio-forms';

const query = (over: Partial<ListQuery> = {}): ListQuery => ({
  query: '',
  filters: {},
  sort: null,
  pageIndex: 0,
  pageSize: 25,
  ...over,
});

describe('portfolioSearch', () => {
  it('asks for the first page and every status when no chip is chosen', () => {
    expect(portfolioSearch(query())).toEqual({
      page: 1,
      itemsPerPage: 25,
      status: [],
      kinds: [],
      customerIds: [],
      intervals: {},
      order: null,
    });
  });

  it('numbers pages from one, carries the chosen chip and the sort the person picked', () => {
    expect(
      portfolioSearch(
        query({
          pageIndex: 2,
          pageSize: 50,
          filters: { status: 'deposited' },
          sort: { column: 'amount', direction: 'desc' },
        }),
      ),
    ).toEqual({
      page: 3,
      itemsPerPage: 50,
      status: ['deposited'],
      kinds: [],
      customerIds: [],
      intervals: {},
      order: { key: 'amount', direction: 'desc' },
    });
  });

  it('knows the chip that narrows the list to what is open, and none it was not told of', () => {
    expect(portfolioSearch(query({ filters: { status: 'open' } })).status).toEqual(['open']);
    expect(portfolioSearch(query({ filters: { status: 'all' } })).status).toEqual([]);
    expect(portfolioSearch(query({ filters: { status: 'nonsense' } })).status).toEqual([]);
  });

  it('combines statuses, kinds and customers, leaving out what the list does not offer', () => {
    const search = portfolioSearch(
      query({
        filters: {
          status: 'cashed,open,nonsense',
          kind: 'draft,cheque',
          customer:
            '01a11304-e8c9-75fd-a04c-ec517b121291,nope,01a11304-e8c9-75fd-a04c-ec517b121292',
        },
      }),
    );
    expect(search.status).toEqual(['open', 'cashed']);
    expect(search.kinds).toEqual(['draft']);
    expect(search.customerIds).toEqual([
      '01a11304-e8c9-75fd-a04c-ec517b121291',
      '01a11304-e8c9-75fd-a04c-ec517b121292',
    ]);
  });

  it('keeps the ends of an interval that are a day or an amount, and drops the rest', () => {
    expect(
      portfolioSearch(
        query({
          filters: {
            'dueOn.from': '2026-02-30',
            'dueOn.to': '2026-03-31',
            'amount.min': '12.5',
            'amount.max': '-1',
          },
        }),
      ).intervals,
    ).toEqual({ 'dueOn.to': '2026-03-31', 'amount.min': '12.5' });
  });

  it('sorts only by what the API can order a page by', () => {
    expect(
      portfolioSearch(query({ sort: { column: 'customer', direction: 'asc' } })).order,
    ).toBeNull();
  });

  it('declares the sortable columns the search reads, so a column cannot be sortable and ignored', () => {
    const sortable = PORTFOLIO_LIST.columns.filter((column) => column.sortable).map((c) => c.id);
    for (const column of sortable) {
      expect(
        portfolioSearch(query({ sort: { column, direction: 'asc' } })).order,
        column,
      ).not.toBeNull();
    }
  });
});
