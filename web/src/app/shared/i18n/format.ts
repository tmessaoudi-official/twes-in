// SPDX-License-Identifier: AGPL-3.0-or-later

/** A decimal as the API writes one: an optional minus, digits, then optional decimals. */
const DECIMAL = /^-?\d+(?:\.\d+)?$/;
const DAY = /^(\d{4})-(\d{2})-(\d{2})$/;
const MOMENT = /^\d{4}-\d{2}-\d{2}T/;

/**
 * An amount as the API takes it and an input holds it: at the currency's scale, and finer only when the value itself
 * is ("0.0045" per unit). Nothing is rounded here; the API computes every figure, and trailing zeros past the scale
 * are dropped.
 */
export function atScale(value: string, scale: number): string {
  const [units = '', decimals = ''] = value.split('.');
  const significant = decimals.replace(/0+$/, '');
  const shown =
    significant.length > scale ? significant : decimals.slice(0, scale).padEnd(scale, '0');
  return shown === '' ? units : `${units}.${shown}`;
}

/**
 * An amount as a person reads it: at the currency's scale as `atScale` has it, grouped and punctuated as the locale
 * does ("2 975,000" in fr-TN). The digits stay the API's own string and never pass through a float, so no figure
 * changes on the way. A scale not known yet keeps the decimals as they came; what is not a decimal shows as it came.
 */
export function formatAmount(value: string, scale: number | null, locale: string): string {
  if (!DECIMAL.test(value)) return value;
  const negative = value.startsWith('-');
  const unsigned = negative ? value.slice(1) : value;
  const [integer = '0', fraction] = (scale === null ? unsigned : atScale(unsigned, scale)).split(
    '.',
  );
  const { numbers, decimal, minus } = numberFormat(locale);
  const shown = `${numbers.format(BigInt(integer))}${fraction === undefined ? '' : decimal + fraction}`;
  return negative ? `${minus}${shown}` : shown;
}

interface NumberFormat {
  numbers: Intl.NumberFormat;
  decimal: string;
  minus: string;
}

const numberFormats = new Map<string, NumberFormat>();

/** A locale's number format and its glyphs, built once: a list re-renders every amount on each change detection. */
function numberFormat(locale: string): NumberFormat {
  let known = numberFormats.get(locale);
  if (known === undefined) {
    const numbers = new Intl.NumberFormat(locale);
    const parts = numbers.formatToParts(-1.5);
    known = {
      numbers,
      decimal: parts.find((part) => part.type === 'decimal')?.value ?? '.',
      minus: parts.find((part) => part.type === 'minusSign')?.value ?? '-',
    };
    numberFormats.set(locale, known);
  }
  return known;
}

/** A calendar day ("2026-09-05") as the locale writes it, "05/09/2026" in French; what is not a day shows as it came. */
export function formatDay(value: string, locale: string): string {
  const match = DAY.exec(value);
  if (match === null) return value;
  const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])];
  const date = new Date(Date.UTC(year, month - 1, day));
  if (date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) return value;
  return new Intl.DateTimeFormat(locale, {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: 'UTC',
  }).format(date);
}

/**
 * A moment (an ISO date-time) as its day and a 24-hour time in a time zone, the viewer's when none is given; what is
 * not a moment shows as it came.
 */
export function formatMoment(value: string, locale: string, timeZone?: string): string {
  const date = new Date(value);
  if (!MOMENT.test(value) || Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat(locale, {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
    ...(timeZone === undefined ? {} : { timeZone }),
  }).format(date);
}

const dayFormats = new Map<string, Intl.DateTimeFormat>();

/** The calendar day ("2026-09-14") a moment falls on in a time zone; what is not a moment comes back as it came. */
export function dayKey(moment: string, timeZone: string): string {
  const date = new Date(moment);
  if (Number.isNaN(date.getTime())) return moment;
  let format = dayFormats.get(timeZone);
  if (format === undefined) {
    format = new Intl.DateTimeFormat('en-CA', {
      timeZone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    });
    dayFormats.set(timeZone, format);
  }
  const parts = format.formatToParts(date);
  const part = (type: Intl.DateTimeFormatPartTypes) =>
    parts.find((candidate) => candidate.type === type)?.value ?? '';
  return `${part('year')}-${part('month')}-${part('day')}`;
}

/**
 * Today in a time zone, as a date field writes it. A date the API takes may be the company's today at the latest, so
 * a person whose own day has already turned (Paris after midnight, a company in Africa/Tunis) must be proposed the
 * company's day and not their browser's, which the API would refuse. The viewer's own day stands in while the company
 * is not known yet, and for a zone that is no zone at all.
 */
export function todayIn(timeZone: string | null | undefined): string {
  const moment = new Date().toISOString();
  const viewer = Intl.DateTimeFormat().resolvedOptions().timeZone;
  if (!timeZone) return dayKey(moment, viewer);
  try {
    return dayKey(moment, timeZone);
  } catch {
    // DateTimeFormat throws a RangeError for a string that is no time zone ("Mars/Olympus").
    return dayKey(moment, viewer);
  }
}

/** The locale figures are written in: the interface language for the company's country ("fr-TN"), else the language. */
export function formatLocale(language: string, country: string | null | undefined): string {
  if (!country) return language;
  try {
    return Intl.getCanonicalLocales(`${language}-${country}`)[0] ?? language;
  } catch {
    // getCanonicalLocales throws a RangeError for a tag that is no locale at all ("fr-not a country").
    return language;
  }
}
