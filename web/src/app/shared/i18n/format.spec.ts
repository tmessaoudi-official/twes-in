// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  atScale,
  dayKey,
  decimalShown,
  decimalTyped,
  formatAmount,
  formatDay,
  formatLongDay,
  formatMonth,
  formatYearMonth,
  formatLocale,
  formatMoment,
  todayIn,
} from './format';

/** ICU groups French thousands with a narrow no-break space; the assertions read it as a space. */
const spaced = (text: string): string => text.replace(/\s/g, ' ');

describe('atScale', () => {
  it('writes an amount the way the API takes it: at the currency scale, and finer only when it is', () => {
    expect(atScale('2525', 3)).toBe('2525.000');
    expect(atScale('2.5000', 3)).toBe('2.500');
    expect(atScale('12.3456', 3)).toBe('12.3456');
    expect(atScale('0.0045', 3)).toBe('0.0045');
    expect(atScale('12.0000', 2)).toBe('12.00');
    expect(atScale('7.0000', 0)).toBe('7');
    expect(atScale('7.5000', 0)).toBe('7.5');
  });
});

describe('a decimal field', () => {
  // docs/SPEC.md § 7, 2026-09-19 21:55: the field shows the screen's decimal separator, never grouped, and takes either.
  it('shows the API value with the locale’s decimal separator and no grouping', () => {
    expect(decimalShown('890.000', 'fr-TN')).toBe('890,000');
    expect(decimalShown('1234567.5', 'fr')).toBe('1234567,5');
    expect(decimalShown('-12.5', 'fr')).toBe('-12,5');
    expect(decimalShown('890.000', 'en-TN')).toBe('890.000');
    expect(decimalShown('42', 'fr')).toBe('42');
  });

  it('shows what is not a decimal as it came', () => {
    expect(decimalShown('', 'fr')).toBe('');
    expect(decimalShown('12,5,3', 'fr')).toBe('12,5,3');
  });

  it('reads a comma or a point as the API’s point, whatever the locale', () => {
    expect(decimalTyped('890,5')).toBe('890.5');
    expect(decimalTyped('890.5')).toBe('890.5');
    expect(decimalTyped(' 12,25 ')).toBe('12.25');
    expect(decimalTyped('')).toBe('');
  });

  it('leaves what cannot be a decimal for the pattern to refuse', () => {
    expect(decimalTyped('12,5,3')).toBe('12,5,3');
    expect(decimalTyped('1 234,5')).toBe('1 234,5');
    expect(decimalTyped('abc')).toBe('abc');
  });
});

describe('formatAmount', () => {
  it('groups the thousands and writes the decimals as the locale does, at the currency scale', () => {
    expect(spaced(formatAmount('2975.000', 3, 'fr-TN'))).toBe('2 975,000');
    expect(formatAmount('2975', 3, 'en')).toBe('2,975.000');
    expect(spaced(formatAmount('1234567.5', 2, 'fr'))).toBe('1 234 567,50');
  });

  it('keeps every digit, finer decimals included: nothing goes through a float', () => {
    expect(spaced(formatAmount('9999999999.9999', 4, 'fr-TN'))).toBe('9 999 999 999,9999');
    expect(spaced(formatAmount('90071992547409.93', 3, 'fr-TN'))).toBe('90 071 992 547 409,930');
    expect(spaced(formatAmount('0.0045', 3, 'fr-TN'))).toBe('0,0045');
  });

  it('keeps the decimals as they came when the scale is not known yet', () => {
    expect(formatAmount('1250.5000', null, 'en')).toBe('1,250.5000');
  });

  it('builds the locale’s number format once, however many amounts a list writes', () => {
    const Real = Intl.NumberFormat;
    const built = vi.spyOn(Intl, 'NumberFormat').mockImplementation(function (
      ...args: ConstructorParameters<typeof Intl.NumberFormat>
    ) {
      return new Real(...args);
    });
    try {
      for (let row = 0; row < 50; row++) formatAmount(`${row}.5`, 3, 'ar-TN');
      expect(built.mock.calls.length).toBeLessThanOrEqual(1);
    } finally {
      built.mockRestore();
    }
  });

  it('signs a negative amount', () => {
    expect(spaced(formatAmount('-1250.5', 3, 'fr-TN'))).toBe('-1 250,500');
  });

  it('shows what is not a decimal as it came', () => {
    expect(formatAmount('abc', 3, 'fr')).toBe('abc');
    expect(formatAmount('', 3, 'fr')).toBe('');
    expect(formatAmount('1e3', 3, 'fr')).toBe('1e3');
  });
});

describe('formatLongDay and formatMonth', () => {
  it('write a day out in full and name its month, whatever the viewer’s time zone', () => {
    expect(formatLongDay('2026-09-16', 'fr-TN')).toMatch(/mercredi 16 septembre 2026/i);
    expect(formatMonth('2026-09-21', 'en')).toBe('September');
    expect(formatMonth('2026-09-01', 'fr-TN', 'short')).toMatch(/^sept/);
    expect(formatLongDay('soon', 'fr')).toBe('soon');
  });

  it('names a month of a year, for a monthly declaration', () => {
    expect(formatYearMonth('2026-09', 'fr-TN')).toBe('septembre 2026');
    expect(formatYearMonth('2026-01', 'en')).toBe('January 2026');
    expect(formatYearMonth('later', 'fr')).toBe('later');
  });
});

describe('formatDay', () => {
  it('writes a calendar day day-first in French and month-first in English, whatever the time zone', () => {
    expect(formatDay('2026-09-05', 'fr-TN')).toBe('05/09/2026');
    expect(formatDay('2026-09-05', 'en')).toBe('09/05/2026');
    expect(formatDay('2026-12-31', 'fr')).toBe('31/12/2026');
  });

  it('shows what is not a day as it came', () => {
    expect(formatDay('soon', 'fr')).toBe('soon');
    expect(formatDay('2026-13-40', 'fr')).toBe('2026-13-40');
  });
});

describe('formatMoment', () => {
  it('writes a moment as its day and a 24-hour time in the given time zone', () => {
    expect(formatMoment('2026-09-13T10:00:00+00:00', 'fr-TN', 'UTC')).toBe('13/09/2026 10:00');
    expect(formatMoment('2026-09-13T22:30:00+00:00', 'fr', 'Africa/Tunis')).toBe(
      '13/09/2026 23:30',
    );
    expect(formatMoment('2026-09-13T10:00:00+00:00', 'en', 'UTC')).toMatch(
      /^09\/13\/2026,? 10:00$/,
    );
  });

  it('shows what is not a moment as it came', () => {
    expect(formatMoment('later', 'fr', 'UTC')).toBe('later');
  });
});

describe('dayKey', () => {
  it('names the calendar day a moment falls on in a time zone', () => {
    // 23:30 in London's winter-free UTC is already half past midnight in Tunis.
    expect(dayKey('2026-09-13T23:30:00+00:00', 'Africa/Tunis')).toBe('2026-09-14');
    expect(dayKey('2026-09-13T23:30:00+00:00', 'UTC')).toBe('2026-09-13');
  });

  it('shows what is not a moment as it came', () => {
    expect(dayKey('someday', 'UTC')).toBe('someday');
  });
});

describe('todayIn', () => {
  // Only Date is faked: a faked setTimeout never fires, and anything awaiting a timer would hang on it.
  const at = (moment: string, body: () => void): void => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date(moment));
    try {
      body();
    } finally {
      vi.useRealTimers();
    }
  };

  it('answers the day in the given zone, which is not the day everywhere', () => {
    // 02:00 UTC is still the 15th in New York, and already the 16th in UTC itself and in Tunis.
    at('2026-09-16T02:00:00Z', () => {
      expect(todayIn('America/New_York')).toBe('2026-09-15');
      expect(todayIn('UTC')).toBe('2026-09-16');
      expect(todayIn('Africa/Tunis')).toBe('2026-09-16');
    });
  });

  it('falls back to the viewer’s own day for a company not read yet, and for a zone that is none', () => {
    at('2026-09-16T02:00:00Z', () => {
      const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
      const viewer = dayKey('2026-09-16T02:00:00Z', zone);
      expect(todayIn(null)).toBe(viewer);
      expect(todayIn(undefined)).toBe(viewer);
      expect(todayIn('')).toBe(viewer);
      expect(todayIn('Mars/Olympus')).toBe(viewer);
    });
  });
});

describe('formatLocale', () => {
  it('pairs the interface language with the company’s country, or keeps the language alone', () => {
    expect(formatLocale('fr', 'TN')).toBe('fr-TN');
    expect(formatLocale('en', 'tn')).toBe('en-TN');
    expect(formatLocale('fr', null)).toBe('fr');
    expect(formatLocale('fr', '')).toBe('fr');
    expect(formatLocale('fr', 'not a country')).toBe('fr');
  });
});

// docs/SPEC.md § 7, 2026-09-25 12:45, row 130: a date and a number format chosen, whatever the language.
describe('a chosen format', () => {
  it('groups and punctuates an amount as chosen, and follows the locale when the choice is auto', () => {
    expect(formatAmount('2975.5', 3, 'en', 'space-comma')).toBe('2 975,500');
    expect(formatAmount('-1234567', 2, 'fr', 'dot-comma')).toBe('-1.234.567,00');
    expect(formatAmount('2975', 3, 'fr-TN', 'comma-dot')).toBe('2,975.000');
    expect(formatAmount('12', 3, 'fr', 'comma-dot')).toBe('12.000');
    expect(formatAmount('2975', 3, 'en', 'auto')).toBe(formatAmount('2975', 3, 'en'));
  });

  it('shows a decimal field with the chosen separator, still never grouped', () => {
    expect(decimalShown('1234.5', 'fr', 'comma-dot')).toBe('1234.5');
    expect(decimalShown('1234.5', 'en', 'space-comma')).toBe('1234,5');
    expect(decimalShown('1234.5', 'en', 'dot-comma')).toBe('1234,5');
    expect(decimalShown('1234.5', 'fr', 'auto')).toBe('1234,5');
  });

  it('writes a day in the chosen order, and as the locale does when the choice is auto', () => {
    expect(formatDay('2026-09-05', 'en', 'dmy')).toBe('05/09/2026');
    expect(formatDay('2026-09-05', 'fr', 'mdy')).toBe('09/05/2026');
    expect(formatDay('2026-09-05', 'fr', 'ymd')).toBe('2026-09-05');
    expect(formatDay('2026-09-05', 'fr', 'dmy-dots')).toBe('05.09.2026');
    expect(formatDay('2026-09-05', 'en', 'auto')).toBe('09/05/2026');
    expect(formatDay('2026-02-30', 'fr', 'ymd')).toBe('2026-02-30');
  });

  it('writes a moment’s day in the chosen order, its time in the given time zone', () => {
    const moment = '2026-09-13T22:30:00+00:00';
    expect(formatMoment(moment, 'en', 'Africa/Tunis', 'ymd')).toBe('2026-09-13 23:30');
    expect(formatMoment(moment, 'fr', 'Asia/Tokyo', 'dmy-dots')).toBe('14.09.2026 07:30');
    expect(formatMoment(moment, 'fr', 'UTC', 'auto')).toBe(formatMoment(moment, 'fr', 'UTC'));
  });
});
