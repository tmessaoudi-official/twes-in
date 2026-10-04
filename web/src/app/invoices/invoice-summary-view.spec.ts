// SPDX-License-Identifier: AGPL-3.0-or-later

import { agingBars, chaseDue, collectedBars, initials, versus } from './invoice-summary-view';

describe('invoice summary view', () => {
  it('compares an amount with last month’s in exact decimals: up, down or the same, the size always positive', () => {
    expect(versus('1881.000', '1850.050')).toEqual({ kind: 'up', amount: '30.950' });
    expect(versus('800.000', '1200.000')).toEqual({ kind: 'down', amount: '400.000' });
    expect(versus('10.00', '10.000')).toEqual({ kind: 'same', amount: '0.000' });
    expect(versus('-20.000', '30.500')).toEqual({ kind: 'down', amount: '50.500' });
    expect(versus('0.1', '0.3')).toEqual({ kind: 'down', amount: '0.2' });
  });

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
