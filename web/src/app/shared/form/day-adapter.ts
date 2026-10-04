// SPDX-License-Identifier: AGPL-3.0-or-later

import { Injectable } from '@angular/core';
import { NativeDateAdapter } from '@angular/material/core';

interface WeekLocale {
  getWeekInfo?: () => { firstDay: number };
  weekInfo?: { firstDay: number };
}

/**
 * Material's native adapter, with the week starting where the locale starts it: the native one always begins on
 * Sunday, which is wrong for a French or Tunisian calendar.
 */
@Injectable()
export class DayAdapter extends NativeDateAdapter {
  override getFirstDayOfWeek(): number {
    try {
      // Not in TypeScript's lib yet; `weekInfo` is the older spelling of the same data in Chromium before 130.
      const locale = new Intl.Locale(this.locale as string) as unknown as WeekLocale;
      const info = locale.getWeekInfo?.() ?? locale.weekInfo;
      return info ? info.firstDay % 7 : 1;
    } catch {
      return 1;
    }
  }
}

/** A calendar date from an ISO day, in the browser's own zone so its day number is the one written. */
export function dateOfDay(day: string): Date | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(day);
  return match ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : null;
}

/** The ISO day of a calendar date. */
export function dayOfDate(date: Date): string {
  const two = (value: number): string => String(value).padStart(2, '0');
  return `${String(date.getFullYear()).padStart(4, '0')}-${two(date.getMonth() + 1)}-${two(date.getDate())}`;
}
