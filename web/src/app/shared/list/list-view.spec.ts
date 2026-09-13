// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ListDescriptor, ListPreferences } from './list-types';
import { NO_LIST_PREFERENCES } from './list-types';
import {
  DuplicateListColumn,
  filterRows,
  paginate,
  resolveColumns,
  sortRows,
  withCustomColumns,
} from './list-view';

interface Customer {
  id: string;
  name: string;
  city: string | null;
  balance: number | null;
  vat: string;
}

const customers: ListDescriptor<Customer> = {
  id: 'customers',
  rowId: (row) => row.id,
  pageSizes: [10, 25],
  columns: [
    {
      id: 'name',
      label: 'customers.name',
      value: (row) => row.name,
      sortable: true,
      filterable: true,
      hideable: false,
    },
    {
      id: 'city',
      label: 'customers.city',
      value: (row) => row.city,
      sortable: true,
      filterable: true,
    },
    {
      id: 'balance',
      label: 'customers.balance',
      value: (row) => row.balance,
      sortable: true,
      align: 'end',
      width: 120,
    },
    {
      id: 'vat',
      label: 'customers.vat',
      value: (row) => row.vat,
      filterable: true,
      defaultHidden: true,
    },
  ],
};

const prefs = (overrides: Partial<ListPreferences>): ListPreferences => ({
  ...NO_LIST_PREFERENCES,
  ...overrides,
});
const ids = (columns: { id: string }[]) => columns.map((column) => column.id);

const rows: Customer[] = [
  { id: '1', name: 'Zébra SARL', city: 'Sfax', balance: 20, vat: 'TN-001' },
  { id: '2', name: 'acme', city: null, balance: 100, vat: 'FR-002' },
  { id: '3', name: 'Élan', city: 'Paris', balance: null, vat: 'FR-003' },
  { id: '4', name: 'Bravo', city: 'Tunis', balance: 3, vat: 'TN-004' },
];

describe('resolveColumns', () => {
  it('shows the declared columns in declared order, without the ones hidden by default', () => {
    expect(ids(resolveColumns(customers, NO_LIST_PREFERENCES))).toEqual([
      'name',
      'city',
      'balance',
    ]);
  });

  it('follows the order a person chose, keeping columns they never placed at the end', () => {
    expect(ids(resolveColumns(customers, prefs({ order: ['balance', 'name'] })))).toEqual([
      'balance',
      'name',
      'city',
    ]);
  });

  it('hides what a person hid, but never a column the descriptor says must stay', () => {
    expect(ids(resolveColumns(customers, prefs({ hidden: ['city', 'name'] })))).toEqual([
      'name',
      'balance',
    ]);
  });

  it('shows a column hidden by default once a person placed it', () => {
    expect(ids(resolveColumns(customers, prefs({ order: ['name', 'vat'] })))).toEqual([
      'name',
      'vat',
      'city',
      'balance',
    ]);
  });

  it('ignores preferences naming columns that no longer exist', () => {
    expect(
      ids(resolveColumns(customers, prefs({ order: ['gone', 'city'], hidden: ['gone'] }))),
    ).toEqual(['city', 'name', 'balance']);
  });

  it('uses a chosen width over the declared one', () => {
    const [balance] = resolveColumns(
      customers,
      prefs({ order: ['balance'], widths: { balance: 200 } }),
    );
    expect(balance.width).toBe(200);
    expect(resolveColumns(customers, NO_LIST_PREFERENCES)[2].width).toBe(120);
  });
});

describe('sortRows', () => {
  const columns = customers.columns;

  it('sorts text the way people read it: case and accents do not scatter names', () => {
    expect(
      sortRows(rows, columns, { column: 'name', direction: 'asc' }).map((row) => row.name),
    ).toEqual(['acme', 'Bravo', 'Élan', 'Zébra SARL']);
  });

  it('sorts numbers as numbers, descending on request', () => {
    expect(
      sortRows(rows, columns, { column: 'balance', direction: 'desc' }).map((row) => row.id),
    ).toEqual(['2', '1', '4', '3']);
  });

  it('puts empty values last in both directions', () => {
    expect(sortRows(rows, columns, { column: 'city', direction: 'asc' }).at(-1)?.id).toBe('2');
    expect(sortRows(rows, columns, { column: 'city', direction: 'desc' }).at(-1)?.id).toBe('2');
  });

  it('leaves the order alone without a sort, or for a column that may not be sorted', () => {
    expect(sortRows(rows, columns, null)).toEqual(rows);
    expect(sortRows(rows, columns, { column: 'vat', direction: 'asc' })).toEqual(rows);
    expect(sortRows(rows, columns, { column: 'gone', direction: 'asc' })).toEqual(rows);
  });

  it('never reorders the rows it was given', () => {
    const before = [...rows];
    sortRows(rows, columns, { column: 'name', direction: 'desc' });
    expect(rows).toEqual(before);
  });
});

describe('filterRows', () => {
  const columns = customers.columns;

  it('keeps rows whose filterable text contains the query, ignoring case and accents', () => {
    expect(filterRows(rows, columns, 'ELAN').map((row) => row.id)).toEqual(['3']);
    expect(filterRows(rows, columns, 'tn-').map((row) => row.id)).toEqual(['1', '4']);
  });

  it('does not search columns that are not filterable', () => {
    expect(filterRows(rows, columns, '100')).toEqual([]);
  });

  it('keeps everything for an empty or blank query', () => {
    expect(filterRows(rows, columns, '   ')).toEqual(rows);
  });
});

describe('paginate', () => {
  const many = Array.from({ length: 23 }, (_, index) => index);

  it('cuts one page and reports the total', () => {
    expect(paginate(many, 1, 10)).toEqual({
      rows: [10, 11, 12, 13, 14, 15, 16, 17, 18, 19],
      pageIndex: 1,
      total: 23,
    });
  });

  it('lands on the last page when asked for one past it, as after a filter shrinks the list', () => {
    expect(paginate(many, 9, 10)).toEqual({ rows: [20, 21, 22], pageIndex: 2, total: 23 });
    expect(paginate([], 3, 10)).toEqual({ rows: [], pageIndex: 0, total: 0 });
  });
});

describe('withCustomColumns', () => {
  it('appends the columns an installation configured after the declared ones', () => {
    const extended = withCustomColumns(customers, [
      { id: 'custom.segment', label: 'Segment', value: () => 'retail', filterable: true },
    ]);

    expect(ids(extended.columns)).toEqual(['name', 'city', 'balance', 'vat', 'custom.segment']);
    expect(ids(customers.columns)).toHaveLength(4);
  });

  it('refuses a custom column that would shadow a declared one', () => {
    expect(() =>
      withCustomColumns(customers, [{ id: 'name', label: 'x', value: () => null }]),
    ).toThrow(DuplicateListColumn);
  });
});
