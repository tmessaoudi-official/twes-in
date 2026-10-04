// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { describe, expect, it } from 'vitest';
import { DayAdapter, dateOfDay, dayOfDate } from './day-adapter';

function adapter(locale: string): DayAdapter {
  const made = TestBed.runInInjectionContext(() => new DayAdapter());
  made.setLocale(locale);
  return made;
}

describe('day adapter', () => {
  it('starts the week on Monday in French and on Sunday in American English', () => {
    expect(adapter('fr-TN').getFirstDayOfWeek()).toBe(1);
    expect(adapter('en-US').getFirstDayOfWeek()).toBe(0);
  });

  it('turns an ISO day into the calendar date written and back, with no zone shift', () => {
    const date = dateOfDay('2026-03-01')!;
    expect([date.getFullYear(), date.getMonth(), date.getDate()]).toEqual([2026, 2, 1]);
    expect(dayOfDate(date)).toBe('2026-03-01');
    expect(dateOfDay('2026-3-1')).toBeNull();
    expect(dateOfDay('')).toBeNull();
  });
});
