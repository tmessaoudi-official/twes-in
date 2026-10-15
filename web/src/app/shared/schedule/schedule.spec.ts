// SPDX-License-Identifier: AGPL-3.0-or-later

import { buildFormGroup } from '../form/form-builder';
import { applicableValues, applyVisibility } from '../form/form-visibility';
import type { FormDescriptor } from '../form/form-types';
import {
  scheduleFields,
  scheduleOf,
  scheduleSentence,
  scheduleValues,
  weekdayName,
  type Schedule,
} from './schedule';

const MONTHLY: Schedule = {
  unit: 'month',
  every: 1,
  anchor: 'date',
  monthDay: 5,
  ordinal: null,
  weekday: null,
};

/** What a translation reads like here: its key and its parameters, so a test sees which text and with what. */
function say(key: string, params: Record<string, unknown> = {}): string {
  const written = Object.entries(params)
    .map(([name, value]) => `${name}=${String(value)}`)
    .join(' ');
  return written === '' ? key : `${key}(${written})`;
}

function form(startsOn = '2026-10-15'): FormDescriptor {
  return {
    id: 'schedule',
    sections: [{ id: 'when', title: 't.when', fields: scheduleFields('fr', startsOn) }],
  };
}

describe('schedule', () => {
  it('names a weekday from 1 for Monday to 7 for Sunday, in the language asked', () => {
    expect(weekdayName(1, 'fr')).toBe('lundi');
    expect(weekdayName(7, 'fr')).toBe('dimanche');
    expect(weekdayName(2, 'en')).toBe('Tuesday');
  });

  it('starts as each month on the first day’s date, the weekday and its rank read from that day too', () => {
    // 15 October 2026 is the third Thursday of its month.
    const group = buildFormGroup(form('2026-10-15'));

    expect(group.getRawValue()).toEqual({
      unit: 'month',
      every: 1,
      anchor: 'date',
      monthDay: 15,
      ordinal: '3',
      weekday: '4',
    });
  });

  it('asks a month’s or a year’s day only for a month or a year, and each anchor’s own figures only for it', () => {
    const descriptor = form();
    const group = buildFormGroup(descriptor, { unit: 'week', anchor: 'date' });
    applyVisibility(descriptor, group);

    expect(Object.keys(applicableValues(descriptor, group))).toEqual(['unit', 'every']);

    group.controls['unit'].setValue('month');
    group.controls['anchor'].setValue('weekday');
    applyVisibility(descriptor, group);

    expect(Object.keys(applicableValues(descriptor, group))).toEqual([
      'unit',
      'every',
      'anchor',
      'ordinal',
      'weekday',
    ]);
  });

  it('reads what a form holds as the schedule the API takes, every figure the anchor does not use left out', () => {
    expect(
      scheduleOf({
        unit: 'week',
        every: 2,
        anchor: 'date',
        monthDay: 5,
        ordinal: '1',
        weekday: '3',
      }),
    ).toEqual({
      unit: 'week',
      every: 2,
      anchor: null,
      monthDay: null,
      ordinal: null,
      weekday: null,
    });
    expect(
      scheduleOf({ unit: 'month', every: 3, anchor: 'last_weekday', ordinal: '2', weekday: '5' }),
    ).toEqual({
      unit: 'month',
      every: 3,
      anchor: 'last_weekday',
      monthDay: null,
      ordinal: null,
      weekday: 5,
    });
    expect(scheduleOf({ unit: 'year', every: '1', anchor: 'date', monthDay: '31' })).toEqual({
      unit: 'year',
      every: 1,
      anchor: 'date',
      monthDay: 31,
      ordinal: null,
      weekday: null,
    });
  });

  it('gives back a schedule as the values its form shows', () => {
    const schedule: Schedule = {
      ...MONTHLY,
      anchor: 'weekday',
      monthDay: null,
      ordinal: 2,
      weekday: 6,
    };

    expect(scheduleOf(scheduleValues(schedule))).toEqual(schedule);
  });

  it('says how often in one sentence, its day read in the language asked', () => {
    expect(
      scheduleSentence(
        { ...MONTHLY, unit: 'day', anchor: null, monthDay: null },
        '2026-10-15',
        say,
        'fr',
      ),
    ).toBe('schedule.every.day(every=1)');
    expect(
      scheduleSentence(
        { ...MONTHLY, unit: 'week', every: 2, anchor: null, monthDay: null },
        '2026-10-15',
        say,
        'fr',
      ),
    ).toBe('schedule.on.week(every=schedule.every.week(every=2) weekday=jeudi)');
    expect(scheduleSentence(MONTHLY, '2026-10-15', say, 'fr')).toBe(
      'schedule.on.date(every=schedule.every.month(every=1) day=5)',
    );
    expect(
      scheduleSentence(
        { ...MONTHLY, anchor: 'weekday', monthDay: null, ordinal: 2, weekday: 2 },
        '2026-10-15',
        say,
        'fr',
      ),
    ).toBe(
      'schedule.on.weekday(every=schedule.every.month(every=1) ordinal=schedule.ordinals.2 weekday=mardi)',
    );
    expect(
      scheduleSentence(
        { ...MONTHLY, anchor: 'last_weekday', monthDay: null, weekday: 5 },
        '2026-10-15',
        say,
        'fr',
      ),
    ).toBe('schedule.on.last_weekday(every=schedule.every.month(every=1) weekday=vendredi)');
    expect(
      scheduleSentence({ ...MONTHLY, anchor: 'last_day', monthDay: null }, '2026-10-15', say, 'fr'),
    ).toBe('schedule.on.last_day(every=schedule.every.month(every=1))');
  });

  it('names the month a year falls in, the first day’s own', () => {
    expect(scheduleSentence({ ...MONTHLY, unit: 'year' }, '2026-03-02', say, 'fr')).toBe(
      'schedule.in_month(on=schedule.on.date(every=schedule.every.year(every=1) day=5) month=mars)',
    );
  });
});
