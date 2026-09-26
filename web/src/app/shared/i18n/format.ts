// SPDX-License-Identifier: AGPL-3.0-or-later

/** A decimal as the API writes one: an optional minus, digits, then optional decimals. */
const DECIMAL = /^-?\d+(?:\.\d+)?$/;
const DAY = /^(\d{4})-(\d{2})-(\d{2})$/;
const MOMENT = /^\d{4}-\d{2}-\d{2}T/;

/**
 * How a person chose to see days and figures (docs/SPEC.md § 7, 2026-09-25 12:45, row 130), whatever the language:
 * `auto` writes them as the locale does, which is what everything showed before the choice existed.
 */
export const DATE_FORMATS = ['auto', 'dmy', 'mdy', 'ymd', 'dmy-dots'] as const;
export type DateFormat = (typeof DATE_FORMATS)[number];
export const NUMBER_FORMATS = ['auto', 'space-comma', 'dot-comma', 'comma-dot'] as const;
export type NumberStyle = (typeof NUMBER_FORMATS)[number];

/** Each chosen number style's thousands and decimal separators; the space is ICU's narrow no-break one, as French. */
const NUMBER_STYLES: Readonly<Record<Exclude<NumberStyle, 'auto'>, readonly [string, string]>> = {
  'space-comma': ['\u202f', ','],
  'dot-comma': ['.', ','],
  'comma-dot': [',', '.'],
};

/**
 * An amount as the API takes it and a form control holds it: at the currency's scale, and finer only when the value
 * itself is ("0.0045" per unit). Nothing is rounded here; the API computes every figure, and trailing zeros past the
 * scale are dropped. The field shows it through `decimalShown`.
 */
export function atScale(value: string, scale: number): string {
  const [units = '', decimals = ''] = value.split('.');
  const significant = decimals.replace(/0+$/, '');
  const shown =
    significant.length > scale ? significant : decimals.slice(0, scale).padEnd(scale, '0');
  return shown === '' ? units : `${units}.${shown}`;
}

/**
 * A decimal field's text for the API's value: the locale's decimal separator and nothing else, never grouped, so
 * "890.000" shows "890,000" in French and a typed "1,234" can never mean a thousand (docs/SPEC.md § 7, 2026-09-19).
 * What is not a decimal shows as it came.
 */
export function decimalShown(value: string, locale: string, style: NumberStyle = 'auto'): string {
  return DECIMAL.test(value) ? value.replace('.', numberFormat(locale, style).decimal) : value;
}

/** What a person typed in a decimal field, as the API reads it: a comma or a point; anything else is left to refuse. */
export function decimalTyped(text: string): string {
  const trimmed = text.trim();
  return /^-?\d+,\d+$/.test(trimmed) ? trimmed.replace(',', '.') : trimmed;
}

/**
 * An amount as a person reads it: at the currency's scale as `atScale` has it, grouped and punctuated as the locale
 * does ("2 975,000" in fr-TN). The digits stay the API's own string and never pass through a float, so no figure
 * changes on the way. A scale not known yet keeps the decimals as they came; what is not a decimal shows as it came.
 */
export function formatAmount(
  value: string,
  scale: number | null,
  locale: string,
  style: NumberStyle = 'auto',
): string {
  if (!DECIMAL.test(value)) return value;
  const negative = value.startsWith('-');
  const unsigned = negative ? value.slice(1) : value;
  const [integer = '0', fraction] = (scale === null ? unsigned : atScale(unsigned, scale)).split(
    '.',
  );
  const { group, decimal, minus } = numberFormat(locale, style);
  const shown = `${group(integer)}${fraction === undefined ? '' : decimal + fraction}`;
  return negative ? `${minus}${shown}` : shown;
}

interface NumberFormat {
  /** The integer part's digits, grouped. */
  group: (digits: string) => string;
  decimal: string;
  minus: string;
}

const numberFormats = new Map<string, NumberFormat>();

/**
 * A locale's number format and its glyphs, or a chosen style's, built once: a list re-renders every amount on each
 * change detection.
 */
function numberFormat(locale: string, style: NumberStyle = 'auto'): NumberFormat {
  if (style !== 'auto') {
    const [thousands, decimal] = NUMBER_STYLES[style];
    return {
      group: (digits) => digits.replace(/\B(?=(\d{3})+(?!\d))/g, thousands),
      decimal,
      minus: '-',
    };
  }
  let known = numberFormats.get(locale);
  if (known === undefined) {
    const numbers = new Intl.NumberFormat(locale);
    const parts = numbers.formatToParts(-1.5);
    known = {
      group: (digits) => numbers.format(BigInt(digits)),
      decimal: parts.find((part) => part.type === 'decimal')?.value ?? '.',
      minus: parts.find((part) => part.type === 'minusSign')?.value ?? '-',
    };
    numberFormats.set(locale, known);
  }
  return known;
}

/** A day's two-digit parts in a chosen order. */
function dayInOrder(
  year: string,
  month: string,
  day: string,
  style: Exclude<DateFormat, 'auto'>,
): string {
  switch (style) {
    case 'dmy':
      return `${day}/${month}/${year}`;
    case 'mdy':
      return `${month}/${day}/${year}`;
    case 'ymd':
      return `${year}-${month}-${day}`;
    case 'dmy-dots':
      return `${day}.${month}.${year}`;
  }
}

/** A calendar day ("2026-09-05") as the locale writes it, "05/09/2026" in French; what is not a day shows as it came. */
export function formatDay(value: string, locale: string, style: DateFormat = 'auto'): string {
  const match = DAY.exec(value);
  if (match === null) return value;
  const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])];
  const date = new Date(Date.UTC(year, month - 1, day));
  if (date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) return value;
  if (style !== 'auto') return dayInOrder(match[1]!, match[2]!, match[3]!, style);
  return new Intl.DateTimeFormat(locale, {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: 'UTC',
  }).format(date);
}

/** A calendar day written out in full, "mercredi 16 septembre 2026" in French; what is not a day shows as it came. */
export function formatLongDay(value: string, locale: string): string {
  return formatDayParts(value, locale, {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  });
}

/** The month of a calendar day, named in full ("September"), or short ("sept.") for a chart's axis. */
export function formatMonth(
  value: string,
  locale: string,
  width: 'long' | 'short' = 'long',
): string {
  return formatDayParts(value, locale, { month: width });
}

/** A month of a year, `YYYY-MM`, named in full ("septembre 2026"); what is not one shows as it came. */
export function formatYearMonth(value: string, locale: string): string {
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(value)) return value;
  return formatDayParts(`${value}-01`, locale, { month: 'long', year: 'numeric' });
}

function formatDayParts(value: string, locale: string, parts: Intl.DateTimeFormatOptions): string {
  const match = DAY.exec(value);
  if (match === null) return value;
  const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
  return new Intl.DateTimeFormat(locale, { ...parts, timeZone: 'UTC' }).format(date);
}

/**
 * A moment (an ISO date-time) as its day and a 24-hour time in a time zone, the viewer's when none is given; what is
 * not a moment shows as it came.
 */
export function formatMoment(
  value: string,
  locale: string,
  timeZone?: string,
  style: DateFormat = 'auto',
): string {
  const date = new Date(value);
  if (!MOMENT.test(value) || Number.isNaN(date.getTime())) return value;
  if (style !== 'auto') {
    const parts = new Intl.DateTimeFormat('en-CA', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
      ...(timeZone === undefined ? {} : { timeZone }),
    }).formatToParts(date);
    const part = (type: Intl.DateTimeFormatPartTypes) =>
      parts.find((candidate) => candidate.type === type)?.value ?? '';
    return `${dayInOrder(part('year'), part('month'), part('day'), style)} ${part('hour')}:${part('minute')}`;
  }
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
