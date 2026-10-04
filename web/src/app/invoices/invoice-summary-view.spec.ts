// SPDX-License-Identifier: AGPL-3.0-or-later

import { agingBars, chaseDue, collectedBars, initials } from './invoice-summary-view';

describe('invoice summary view', () => {
  it('says how late an invoice to chase is, or when it falls due', () => {
    expect(chaseDue(41)).toEqual({ key: 'invoices.home.late', days: 41 });
    expect(chaseDue(0)).toEqual({ key: 'invoices.home.due_today', days: 0 });
    expect(chaseDue(-2)).toEqual({ key: 'invoices.home.due_in', days: 2 });
  });

  it('draws each month’s payments against the best month, and nothing when nothing was paid', () => {
    const bars = collectedBars(
      [
        { month: '2026-08', amount: '400.000' },
        { month: '2026-09', amount: '800.000' },
      ],
      'fr-TN',
    );
    expect(bars.map((bar) => [bar.month, bar.height, bar.current])).toEqual([
      ['2026-08', 50, false],
      ['2026-09', 100, true],
    ]);
    expect(bars[1]?.label).toMatch(/^sept/);
    expect(
      collectedBars([{ month: '2026-09', amount: '0.000' }], 'fr-TN').map((bar) => bar.height),
    ).toEqual([0]);
  });

  it('shares the aging bar by what each bucket has due, leaving out the empty ones', () => {
    const bars = agingBars([
      { bucket: 'not_due', amount: '300.000', count: 1 },
      { bucket: 'days_1_15', amount: '0.000', count: 0 },
      { bucket: 'days_over_45', amount: '100.000', count: 1 },
    ]);
    expect(bars.map((bar) => [bar.bucket, bar.share])).toEqual([
      ['not_due', 75],
      ['days_over_45', 25],
    ]);
    expect(agingBars([{ bucket: 'not_due', amount: '0.000', count: 0 }])).toEqual([]);
  });

  it('writes a customer’s initials', () => {
    expect(initials('Garage Ben Arous')).toBe('GB');
    expect(initials('  café  ')).toBe('C');
    expect(initials('')).toBe('?');
  });
});
