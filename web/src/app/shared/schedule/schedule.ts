// SPDX-License-Identifier: AGPL-3.0-or-later

import type { FieldValue, FormField, FormValues } from '../form/form-types';

/** How far apart the occurrences are counted, as the API names it. */
export const SCHEDULE_UNITS = ['day', 'week', 'month', 'year'] as const;
export type ScheduleUnit = (typeof SCHEDULE_UNITS)[number];

/** Which day of a month a month or a year falls on: a date, the last day, the nth weekday, the last weekday. */
export const SCHEDULE_ANCHORS = ['date', 'last_day', 'weekday', 'last_weekday'] as const;
export type ScheduleAnchor = (typeof SCHEDULE_ANCHORS)[number];

/** The most apart two occurrences may be, in their unit: the API refuses more. */
export const MOST_APART = 99;

/** The schedule's own fields a refusal may name, each with its words under `schedule.errors`. */
const REFUSED_FIELDS = new Set(['every', 'monthDay', 'ordinal', 'weekday']);

/** A refused field's words when it is the schedule's own, null for another the form names. */
export function scheduleFailure(field: string | null): string | null {
  return field !== null && REFUSED_FIELDS.has(field) ? `schedule.errors.${field}` : null;
}

/**
 * When something recurs, as the API writes it flat: every so many days, weeks, months or years, a month or a year on
 * the day it is anchored to. Each figure is null where its anchor does not read it.
 */
export interface Schedule {
  readonly unit: ScheduleUnit;
  readonly every: number;
  readonly anchor: ScheduleAnchor | null;
  /** The date of the month, 1 to 31; a shorter month takes its last day. */
  readonly monthDay: number | null;
  /** Which of the month's weekdays, the first to the fourth. */
  readonly ordinal: number | null;
  /** 1 for Monday to 7 for Sunday, as ISO 8601 counts them. */
  readonly weekday: number | null;
}

const ANCHORED: string[] = ['month', 'year'];

/**
 * A weekday's name in the locale the screen writes its dates in (`FormatFacade.locale`): 1 for Monday to 7 for Sunday.
 * The 1st of January 2024 was a Monday.
 */
export function weekdayName(weekday: number, locale: string): string {
  return new Intl.DateTimeFormat(locale, { weekday: 'long', timeZone: 'UTC' }).format(
    Date.UTC(2024, 0, weekday),
  );
}

/** A month's name as the screen's dates write it: Tunisian Arabic says « جانفي » where Arabic alone says « يناير ». */
function monthName(day: string, locale: string): string {
  return new Intl.DateTimeFormat(locale, { month: 'long', timeZone: 'UTC' }).format(
    new Date(`${day}T00:00:00Z`),
  );
}

/** The day of the month, its ISO weekday and its rank among that month's same weekdays (the fifth read as the fourth). */
function dayParts(day: string): { date: number; weekday: number; rank: number } {
  const moment = new Date(`${day}T00:00:00Z`);
  const date = Number.isNaN(moment.getTime()) ? 1 : moment.getUTCDate();
  const weekday = Number.isNaN(moment.getTime()) ? 1 : ((moment.getUTCDay() + 6) % 7) + 1;
  return { date, weekday, rank: Math.min(4, Math.ceil(date / 7)) };
}

/**
 * The fields that ask how often, in the order they read: the unit, how many of them apart, then a month's or a year's
 * day and only the figures that day takes. They start as each month on `startsOn`'s date, and the weekday and its rank
 * are read from that day too, so switching to « le 3e jeudi » offers the first day's own. Weekday names come from the
 * calendar of the locale the screen writes its dates in, not from screen texts.
 */
export function scheduleFields(locale: string, startsOn: string): FormField[] {
  const first = dayParts(startsOn);
  const anchored = { field: 'unit', oneOf: ANCHORED };
  return [
    {
      id: 'unit',
      label: 'schedule.fields.unit',
      kind: 'select',
      required: true,
      defaultValue: 'month',
      options: SCHEDULE_UNITS.map((value) => ({ value, label: `schedule.units.${value}` })),
    },
    {
      id: 'every',
      label: 'schedule.fields.every',
      kind: 'number',
      required: true,
      min: 1,
      max: MOST_APART,
      defaultValue: 1,
      hint: 'schedule.fields.every_hint',
    },
    {
      id: 'anchor',
      label: 'schedule.fields.anchor',
      kind: 'select',
      required: true,
      defaultValue: 'date',
      options: SCHEDULE_ANCHORS.map((value) => ({ value, label: `schedule.anchors.${value}` })),
      visibleWhen: anchored,
    },
    {
      id: 'monthDay',
      label: 'schedule.fields.month_day',
      kind: 'number',
      required: true,
      min: 1,
      max: 31,
      defaultValue: first.date,
      hint: 'schedule.fields.month_day_hint',
      visibleWhen: { field: 'anchor', oneOf: ['date'], and: anchored },
    },
    {
      id: 'ordinal',
      label: 'schedule.fields.ordinal',
      kind: 'select',
      required: true,
      defaultValue: String(first.rank),
      options: [1, 2, 3, 4].map((rank) => ({
        value: String(rank),
        label: `schedule.ordinals.${rank}`,
      })),
      visibleWhen: { field: 'anchor', oneOf: ['weekday'], and: anchored },
    },
    {
      id: 'weekday',
      label: 'schedule.fields.weekday',
      kind: 'select',
      required: true,
      defaultValue: String(first.weekday),
      // A name the screen texts do not hold reads as itself.
      options: [1, 2, 3, 4, 5, 6, 7].map((weekday) => ({
        value: String(weekday),
        label: weekdayName(weekday, locale),
      })),
      visibleWhen: { field: 'anchor', oneOf: ['weekday', 'last_weekday'], and: anchored },
    },
  ];
}

function whole(value: FieldValue | undefined): number | null {
  const number = typeof value === 'number' ? value : Number(String(value ?? '').trim());
  return String(value ?? '').trim() === '' || !Number.isInteger(number) ? null : number;
}

/** What the fields hold as the schedule the API takes: every figure its anchor does not read left null. */
export function scheduleOf(values: FormValues): Schedule {
  const unit = (SCHEDULE_UNITS as readonly string[]).includes(String(values['unit']))
    ? (values['unit'] as ScheduleUnit)
    : 'month';
  const chosen = String(values['anchor'] ?? '');
  const anchor = !ANCHORED.includes(unit)
    ? null
    : (SCHEDULE_ANCHORS as readonly string[]).includes(chosen)
      ? (chosen as ScheduleAnchor)
      : 'date';
  return {
    unit,
    every: whole(values['every']) ?? 1,
    anchor,
    monthDay: anchor === 'date' ? whole(values['monthDay']) : null,
    ordinal: anchor === 'weekday' ? whole(values['ordinal']) : null,
    weekday: anchor === 'weekday' || anchor === 'last_weekday' ? whole(values['weekday']) : null,
  };
}

/** A schedule as its fields show it; a figure its anchor does not read is left to the field's own start. */
export function scheduleValues(schedule: Schedule): FormValues {
  const values: FormValues = { unit: schedule.unit, every: schedule.every };
  if (schedule.anchor !== null) values['anchor'] = schedule.anchor;
  if (schedule.monthDay !== null) values['monthDay'] = schedule.monthDay;
  if (schedule.ordinal !== null) values['ordinal'] = String(schedule.ordinal);
  if (schedule.weekday !== null) values['weekday'] = String(schedule.weekday);
  return values;
}

/** How a screen text is read: its key and its parameters. */
export type Say = (key: string, params?: Record<string, unknown>) => string;

/**
 * How often, in one sentence: « Tous les 3 mois, le dernier vendredi ». A week falls on `startsOn`'s weekday and a
 * year in its month, as the API counts them; weekday and month names come from the calendar of the locale the screen
 * writes its dates in.
 */
export function scheduleSentence(
  schedule: Schedule,
  startsOn: string,
  say: Say,
  locale: string,
): string {
  const every = say(`schedule.every.${schedule.unit}`, { every: schedule.every });
  if (schedule.unit === 'day') return every;
  if (schedule.unit === 'week') {
    return say('schedule.on.week', {
      every,
      weekday: weekdayName(dayParts(startsOn).weekday, locale),
    });
  }
  const anchor = schedule.anchor ?? 'date';
  const weekday = schedule.weekday === null ? '' : weekdayName(schedule.weekday, locale);
  const on = say(`schedule.on.${anchor}`, {
    every,
    ...(anchor === 'date' ? { day: schedule.monthDay } : {}),
    ...(anchor === 'weekday' ? { ordinal: say(`schedule.ordinals.${schedule.ordinal}`) } : {}),
    ...(anchor === 'weekday' || anchor === 'last_weekday' ? { weekday } : {}),
  });
  return schedule.unit === 'year'
    ? say('schedule.in_month', { on, month: monthName(startsOn, locale) })
    : on;
}
