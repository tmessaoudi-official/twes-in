// SPDX-License-Identifier: AGPL-3.0-or-later

import { validRangeValue } from '../shared/list/list-filters';
import {
  REPORT_COMPARISONS,
  type ReportColumn,
  type ReportDocument,
  type ReportDrill,
  type ReportEntry,
  type ReportQuery,
} from './reports-types';

/** The periods a report offers in one choice; anything else is a period of the person's own days. */
export type PeriodPreset = 'this_month' | 'last_month' | 'this_year' | 'last_year';

export const PERIOD_PRESETS: readonly PeriodPreset[] = [
  'this_month',
  'last_month',
  'this_year',
  'last_year',
];

export interface Period {
  readonly from: string;
  readonly to: string;
}

/** A preset's days, from the company's today: whole months and whole years, as the API compares them. */
export function presetPeriod(preset: PeriodPreset, today: string): Period {
  const year = Number(today.slice(0, 4));
  const month = Number(today.slice(5, 7));
  switch (preset) {
    case 'this_month':
      return wholeMonth(year, month);
    case 'last_month':
      return month === 1 ? wholeMonth(year - 1, 12) : wholeMonth(year, month - 1);
    case 'this_year':
      return { from: `${year}-01-01`, to: `${year}-12-31` };
    case 'last_year':
      return { from: `${year - 1}-01-01`, to: `${year - 1}-12-31` };
  }
}

/** The preset a period is, or `custom` for any other days. */
export function presetOf(period: Period, today: string): PeriodPreset | 'custom' {
  return (
    PERIOD_PRESETS.find((preset) => {
      const days = presetPeriod(preset, today);
      return days.from === period.from && days.to === period.to;
    }) ?? 'custom'
  );
}

/** What an address names, as the router's query map answers it. */
export interface QueryValues {
  get(name: string): string | null | undefined;
}

/**
 * What a report is asked from what the address says. A report of a period opens on this month against the month
 * before, what « ce mois-ci » means on the home; a report of today takes no period and has nothing to be compared with.
 * A day the calendar has not, a period that ends before it starts and a comparison there is not are left out, and the
 * opening question stands in their place.
 */
export function queryOf(values: QueryValues, entry: ReportEntry, today: string): ReportQuery {
  const establishment = (entry.byEstablishment ? values.get('establishment') : null) || null;
  if (!entry.periodic) return { from: null, to: null, compare: 'none', establishment };
  const from = values.get('from') ?? '';
  const to = values.get('to') ?? '';
  const period =
    validRangeValue('day', from) && validRangeValue('day', to) && from <= to
      ? { from, to }
      : presetPeriod('this_month', today);
  const asked = values.get('compare');
  const compare = REPORT_COMPARISONS.find((each) => each === asked) ?? 'previous';
  return { ...period, compare, establishment };
}

/** The address of what is asked, so a report opened again, or come back to from a list, reads the same. */
export function addressOf(query: ReportQuery): Record<string, string | null> {
  return {
    from: query.from,
    to: query.to,
    compare: query.from === null ? null : query.compare,
    establishment: query.establishment,
  };
}

/**
 * A column's label and the values it says. The brackets of a series (`late_1`, `late_2`, as many as the company cut)
 * share one label, which their days tell apart.
 */
export function columnLabel(column: ReportColumn): {
  key: string;
  params: Readonly<Record<string, number>>;
} {
  return { key: `reports.columns.${column.key.replace(/_\d+$/, '')}`, params: column.params };
}

/** The lists a row's figures can open, by the name the API gives them. */
const LIST_ROUTES: Readonly<Record<string, string>> = {
  invoices: '/invoices',
  expenses: '/expenses',
  'stock-movements': '/stock/movements',
  activity: '/company/activity',
};

/**
 * Where a row's figures open: the list narrowed by the filters its own address takes, several values joined as the list
 * reads them. None for a row whose documents are the report's own, which the dialog shows, nor for a list this screen
 * does not know.
 */
export function drillLink(
  drill: ReportDrill,
): { commands: string[]; queryParams: Record<string, string> } | null {
  const route = drill.list === null ? undefined : LIST_ROUTES[drill.list];
  if (route === undefined) return null;
  return {
    commands: [route],
    queryParams: Object.fromEntries(
      Object.entries(drill.filters).map(([key, value]) => [
        key,
        typeof value === 'string' ? value : value.join(','),
      ]),
    ),
  };
}

/** A document's own page, or null for a kind this screen does not open. */
export function documentLink(document: ReportDocument): string[] | null {
  switch (document.kind) {
    case 'invoice':
      return ['/invoices', document.id];
    case 'expense':
      return ['/expenses', document.id];
    default:
      return null;
  }
}

function wholeMonth(year: number, month: number): Period {
  const last = new Date(Date.UTC(year, month, 0)).getUTCDate();
  const shown = String(month).padStart(2, '0');
  return { from: `${year}-${shown}-01`, to: `${year}-${shown}-${String(last).padStart(2, '0')}` };
}
