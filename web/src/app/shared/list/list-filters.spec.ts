// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  activeFilterChips,
  filterValues,
  joinValues,
  patchFilters,
  rangeKey,
  toggleValue,
  validRangeValue,
} from './list-filters';
import type { ListDescriptor } from './list-types';

const descriptor: ListDescriptor<{ id: string }> = {
  id: 'docs',
  rowId: (row) => row.id,
  pageSizes: [25],
  columns: [{ id: 'id', label: 'x', value: (row) => row.id }],
  filters: [
    {
      id: 'status',
      label: 'f.status',
      multiple: true,
      value: () => null,
      options: [
        { value: 'draft', label: 'statuses.draft' },
        { value: 'overdue', label: 'statuses.overdue' },
      ],
    },
  ],
  ranges: [
    { id: 'issueDate', label: 'f.issued', kind: 'day' },
    { id: 'totalGross', label: 'f.total', kind: 'amount' },
  ],
  picks: [{ id: 'customer', label: 'f.customer' }],
};

describe('the values of one filter', () => {
  it('are a comma list in the one string a list query keeps for each filter', () => {
    expect(filterValues('draft,overdue')).toEqual(['draft', 'overdue']);
    expect(filterValues('draft')).toEqual(['draft']);
    expect(filterValues('')).toEqual([]);
    expect(filterValues(undefined)).toEqual([]);
    expect(filterValues('draft,,overdue,')).toEqual(['draft', 'overdue']);
    expect(joinValues(['draft', 'overdue'])).toBe('draft,overdue');
  });

  it('are added once and taken out when chosen again', () => {
    expect(toggleValue(undefined, 'draft')).toBe('draft');
    expect(toggleValue('draft', 'overdue')).toBe('draft,overdue');
    expect(toggleValue('draft,overdue', 'draft')).toBe('overdue');
    expect(toggleValue('draft', 'draft')).toBe('');
  });
});

describe('the interval filters', () => {
  it('keep each end under its own key', () => {
    expect(rangeKey('issueDate', 'from')).toBe('issueDate.from');
    expect(rangeKey('totalGross', 'max')).toBe('totalGross.max');
  });

  it('take a real day or a plain amount and nothing else', () => {
    expect(validRangeValue('day', '2026-10-06')).toBe(true);
    expect(validRangeValue('day', '2026-02-30')).toBe(false);
    expect(validRangeValue('day', '06/10/2026')).toBe(false);
    expect(validRangeValue('amount', '1250.5')).toBe(true);
    expect(validRangeValue('amount', '0')).toBe(true);
    expect(validRangeValue('amount', '1,5')).toBe(false);
    expect(validRangeValue('amount', '-1')).toBe(false);
    expect(validRangeValue('amount', '1e3')).toBe(false);
  });
});

describe('patchFilters', () => {
  it('sets the keys given and drops the ones given as null or empty', () => {
    expect(
      patchFilters({ status: 'draft', 'issueDate.from': '2026-10-01' }, { status: null }),
    ).toEqual({
      'issueDate.from': '2026-10-01',
    });
    expect(patchFilters({}, { status: 'draft,overdue', customer: '' })).toEqual({
      status: 'draft,overdue',
    });
  });
});

describe('the chips of what is filtered', () => {
  const names = { c1: 'Carthage Conseil' };

  it('are one per chosen value of a facet, so each can be taken out alone', () => {
    const chips = activeFilterChips(descriptor, { status: 'draft,overdue' }, names);
    expect(
      chips.map((chip) => [chip.labelKey, chip.valueKey ?? chip.valueText, chip.remove]),
    ).toEqual([
      ['f.status', 'statuses.draft', { status: 'overdue' }],
      ['f.status', 'statuses.overdue', { status: 'draft' }],
    ]);
  });

  it('are one per interval, said with its ends, and taken out together', () => {
    const chips = activeFilterChips(
      descriptor,
      { 'issueDate.from': '2026-10-01', 'issueDate.to': '2026-10-31' },
      names,
    );
    expect(chips).toHaveLength(1);
    expect(chips[0]).toMatchObject({
      labelKey: 'f.issued',
      kind: 'range',
      from: '2026-10-01',
      to: '2026-10-31',
      remove: { 'issueDate.from': null, 'issueDate.to': null },
    });
    const open = activeFilterChips(descriptor, { 'totalGross.min': '100' }, names);
    expect(open[0]).toMatchObject({ kind: 'range', from: '100', to: null });
  });

  it('are one per picked record, named when the name is known and by the id until it is', () => {
    const chips = activeFilterChips(descriptor, { customer: 'c1,c2' }, names);
    expect(chips.map((chip) => [chip.valueText, chip.remove])).toEqual([
      ['Carthage Conseil', { customer: 'c2' }],
      ['c2', { customer: 'c1' }],
    ]);
  });

  it('leave out a filter nobody declared and a value its options no longer offer', () => {
    expect(activeFilterChips(descriptor, { nonsense: 'x', status: 'gone' }, {})).toEqual([]);
  });
});
