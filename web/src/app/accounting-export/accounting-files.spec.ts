// SPDX-License-Identifier: AGPL-3.0-or-later

import { accountingFileAddress, isPeriod, lastMonth } from './accounting-files';

describe('the accountant files', () => {
  it('are downloaded from the export engine with the period', () => {
    expect(accountingFileAddress('c/1', 'vat-summary', '2026-09-01', '2026-09-30', 'xlsx')).toBe(
      '/api/companies/c%2F1/exports/vat-summary.xlsx?from=2026-09-01&to=2026-09-30',
    );
  });

  it('cover the month before by default, across a year too', () => {
    expect(lastMonth('2026-10-08')).toEqual({ from: '2026-09-01', to: '2026-09-30' });
    expect(lastMonth('2026-01-15')).toEqual({ from: '2025-12-01', to: '2025-12-31' });
    expect(lastMonth('2024-03-31')).toEqual({ from: '2024-02-01', to: '2024-02-29' });
  });

  it('cover a period the API takes: two days in order, a year apart at most', () => {
    expect(isPeriod('2026-09-01', '2026-09-30')).toBe(true);
    expect(isPeriod('2026-09-30', '2026-09-30')).toBe(true);
    expect(isPeriod('2024-01-01', '2024-12-31')).toBe(true);
    expect(isPeriod('2025-01-01', '2026-01-02')).toBe(false);
    expect(isPeriod('2026-09-30', '2026-09-01')).toBe(false);
    expect(isPeriod('', '2026-09-30')).toBe(false);
    expect(isPeriod('2026-09-01', '')).toBe(false);
  });
});
