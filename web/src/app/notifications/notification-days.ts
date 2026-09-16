// SPDX-License-Identifier: AGPL-3.0-or-later

import { dayKey } from '../shared/i18n/format';

/** The entries of one calendar day, and whether that day is today, yesterday or earlier. */
export interface DayGroup<T> {
  /** the day as "2026-09-14" */
  readonly key: string;
  readonly when: 'today' | 'yesterday' | 'earlier';
  readonly entries: readonly T[];
}

function dayBefore(key: string): string {
  const [year, month, day] = key.split('-').map(Number);
  return new Date(Date.UTC(year, month - 1, day - 1)).toISOString().slice(0, 10);
}

/**
 * Newest-first entries grouped by the day they fall on in a time zone, the company's, so "today" is the same day for
 * everyone in it whatever their own clock says.
 */
export function groupByDay<T extends { readonly createdAt: string }>(
  entries: readonly T[],
  timeZone: string,
  now: Date,
): DayGroup<T>[] {
  const today = dayKey(now.toISOString(), timeZone);
  const yesterday = dayBefore(today);
  const groups: { key: string; when: DayGroup<T>['when']; entries: T[] }[] = [];
  for (const entry of entries) {
    const key = dayKey(entry.createdAt, timeZone);
    const last = groups.at(-1);
    if (last?.key === key) {
      last.entries.push(entry);
    } else {
      const when = key === today ? 'today' : key === yesterday ? 'yesterday' : 'earlier';
      groups.push({ key, when, entries: [entry] });
    }
  }
  return groups;
}

const UNITS: readonly (readonly [Intl.RelativeTimeFormatUnit, number])[] = [
  ['year', 365 * 86_400],
  ['month', 30 * 86_400],
  ['week', 7 * 86_400],
  ['day', 86_400],
  ['hour', 3_600],
  ['minute', 60],
];

/**
 * How long ago a moment was, in its largest whole unit and the locale's words: "il y a 5 minutes", "hier". Under a
 * minute, or a moment slightly ahead of this clock, reads "maintenant"; what is not a moment comes back as it came.
 */
export function relativeTime(moment: string, now: Date, locale: string): string {
  const time = new Date(moment).getTime();
  if (Number.isNaN(time)) return moment;
  const seconds = Math.max(0, Math.floor((now.getTime() - time) / 1000));
  for (const [unit, size] of UNITS) {
    if (seconds >= size) {
      const count = Math.floor(seconds / size);
      // "hier" reads better than "il y a 1 jour"; "la semaine dernière" would wrongly suggest a calendar week.
      const numeric = unit === 'day' && count === 1 ? 'auto' : 'always';
      return new Intl.RelativeTimeFormat(locale, { numeric }).format(-count, unit);
    }
  }
  return new Intl.RelativeTimeFormat(locale, { numeric: 'auto' }).format(0, 'second');
}
