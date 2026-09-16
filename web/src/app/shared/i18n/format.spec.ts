// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  atScale,
  dayKey,
  formatAmount,
  formatDay,
  formatLongDay,
  formatMonth,
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
