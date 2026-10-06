// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ListDescriptor, ListFilterValues } from './list-types';

/**
 * The filters of a list that combine (docs/SPEC.md § 7, 2026-10-06), as pure functions: a list query keeps one string
 * per filter, so the values of a facet or a picker are a comma list in it (no option value or identifier holds a
 * comma), and the two ends of an interval are two keys. Kept as strings, a saved view or an address written before a
 * filter could combine still reads as the one value it holds.
 */

const SEPARATOR = ',';
const DAY = /^\d{4}-\d{2}-\d{2}$/;
const AMOUNT = /^(0|[1-9]\d{0,10})(\.\d{1,4})?$/;

export type RangeKind = 'day' | 'amount';

/** The values one filter holds, none blank. */
export function filterValues(value: string | undefined): string[] {
  return (value ?? '')
    .split(SEPARATOR)
    .map((part) => part.trim())
    .filter((part) => part !== '');
}

export function joinValues(values: readonly string[]): string {
  return values.join(SEPARATOR);
}

/** The values with this one added, or taken out when it is there. */
export function toggleValue(current: string | undefined, value: string): string {
  const held = filterValues(current);
  return joinValues(
    held.includes(value) ? held.filter((each) => each !== value) : [...held, value],
  );
}

/** Where one end of an interval is kept: `issueDate.from`, `totalGross.max`. */
export function rangeKey(id: string, end: 'from' | 'to' | 'min' | 'max'): string {
  return `${id}.${end}`;
}

/** How the API names an interval end a query keeps as `issueDate.from`: `issueDate[from]`. */
export function apiRangeKey(key: string): string {
  const [name, end] = key.split('.');
  return `${name}[${end}]`;
}

/** Whether what was typed is a day that exists, or an amount without sign or exponent: what the API takes. */
export function validRangeValue(kind: RangeKind, value: string): boolean {
  if (kind === 'amount') return AMOUNT.test(value);
  if (!DAY.test(value)) return false;
  const parsed = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === value;
}

/** An interval's two ends, lower first: `from` and `to` for days, `min` and `max` for amounts. */
function endsOf(kind: RangeKind): readonly ['from', 'to'] | readonly ['min', 'max'] {
  return kind === 'day' ? (['from', 'to'] as const) : (['min', 'max'] as const);
}

/** Two plain amounts compared as decimals, never as floats: below zero, zero or above. */
function compareAmounts(a: string, b: string): number {
  const [aWhole = '', aPart = ''] = a.split('.');
  const [bWhole = '', bPart = ''] = b.split('.');
  if (aWhole.length !== bWhole.length) return aWhole.length - bWhole.length;
  const width = Math.max(aPart.length, bPart.length);
  const left = aWhole + aPart.padEnd(width, '0');
  const right = bWhole + bPart.padEnd(width, '0');
  return left < right ? -1 : left > right ? 1 : 0;
}

/** Whether both ends of an interval hold a value and the first comes after the second, which the API refuses. */
export function invertedRange(
  filters: ListFilterValues,
  range: { readonly id: string; readonly kind: RangeKind },
): boolean {
  const [low, high] = endsOf(range.kind);
  const from = filters[rangeKey(range.id, low)];
  const to = filters[rangeKey(range.id, high)];
  if (from === undefined || to === undefined) return false;
  if (!validRangeValue(range.kind, from) || !validRangeValue(range.kind, to)) return false;
  return range.kind === 'day' ? from > to : compareAmounts(from, to) > 0;
}

/**
 * The interval ends a list asks the API for: each a day or an amount the API takes, and an inverted pair left out
 * whole, so the API never refuses the whole list for it; the filter panel says why it is not applied.
 */
export function rangeParams(
  filters: ListFilterValues,
  ranges: readonly { readonly id: string; readonly kind: RangeKind }[],
): Record<string, string> {
  const params: Record<string, string> = {};
  for (const range of ranges) {
    if (invertedRange(filters, range)) continue;
    for (const end of endsOf(range.kind)) {
      const value = filters[rangeKey(range.id, end)];
      if (value !== undefined && validRangeValue(range.kind, value))
        params[rangeKey(range.id, end)] = value;
    }
  }
  return params;
}

const ID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/** The record ids a pick filter holds; anything else in a hand-edited address is left out, as an unknown facet value is. */
export function idValues(value: string | undefined): string[] {
  return filterValues(value).filter((each) => ID.test(each));
}

/** The filters with these keys set, an empty or null one dropped. */
export function patchFilters(
  chosen: ListFilterValues,
  patch: Readonly<Record<string, string | null>>,
): ListFilterValues {
  const next: ListFilterValues = { ...chosen };
  for (const [key, value] of Object.entries(patch)) {
    if (value === null || value === '') delete next[key];
    else next[key] = value;
  }
  return next;
}

/** One removable item of what a list is narrowed by. */
export interface FilterChip {
  /** Unique among the chips, and what a test names. */
  readonly key: string;
  readonly kind: 'facet' | 'range' | 'pick';
  /** The filter's own label, a translation key. */
  readonly labelKey: string;
  /** What a facet's option is called, a translation key. */
  readonly valueKey?: string;
  /** What is shown as it is: a picked record's name. */
  readonly valueText?: string;
  readonly rangeKind?: RangeKind;
  readonly from?: string;
  readonly to?: string | null;
  /** The keys to patch to take it out. */
  readonly remove: Readonly<Record<string, string | null>>;
}

/**
 * A chip for each value a facet holds and each record a picker holds, and one for each interval with either end set. A
 * filter nobody declared, or an option it no longer offers, is left out, as in `applyFilters`: a saved view may outlive
 * the configuration it was saved against.
 */
export function activeFilterChips<Row>(
  descriptor: ListDescriptor<Row>,
  chosen: ListFilterValues,
  pickNames: Readonly<Record<string, string>>,
): FilterChip[] {
  const chips: FilterChip[] = [];
  for (const filter of descriptor.filters ?? []) {
    const held = filterValues(chosen[filter.id]);
    for (const value of held) {
      const option = filter.options.find((each) => each.value === value);
      if (option === undefined) continue;
      chips.push({
        key: `${filter.id}:${value}`,
        kind: 'facet',
        labelKey: filter.label,
        valueKey: option.label,
        remove: { [filter.id]: joinValues(held.filter((each) => each !== value)) },
      });
    }
  }
  for (const range of descriptor.ranges ?? []) {
    const [low, high] =
      range.kind === 'day' ? (['from', 'to'] as const) : (['min', 'max'] as const);
    const from = chosen[rangeKey(range.id, low)];
    const to = chosen[rangeKey(range.id, high)];
    if (from === undefined && to === undefined) continue;
    chips.push({
      key: `${range.id}:range`,
      kind: 'range',
      labelKey: range.label,
      rangeKind: range.kind,
      from: from ?? '',
      to: to ?? null,
      remove: { [rangeKey(range.id, low)]: null, [rangeKey(range.id, high)]: null },
    });
  }
  for (const pick of descriptor.picks ?? []) {
    const held = filterValues(chosen[pick.id]);
    for (const id of held) {
      chips.push({
        key: `${pick.id}:${id}`,
        kind: 'pick',
        labelKey: pick.label,
        valueText: pickNames[id] ?? id,
        remove: { [pick.id]: joinValues(held.filter((each) => each !== id)) },
      });
    }
  }
  return chips;
}
