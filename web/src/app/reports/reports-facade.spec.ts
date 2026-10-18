// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { ReportsApi } from './reports-api';
import { ReportsFacade } from './reports-facade';
import { ReportGone, ReportRefused, type ReportQuery, type ReportResult } from './reports-types';

const QUERY: ReportQuery = {
  from: '2026-09-01',
  to: '2026-09-30',
  compare: 'none',
  establishment: null,
};

function result(key: string): ReportResult {
  return {
    key,
    from: '2026-09-01',
    to: '2026-09-30',
    comparisonFrom: null,
    comparisonTo: null,
    columns: [],
    rows: [],
    totals: {},
    previousTotals: null,
  };
}

describe('ReportsFacade', () => {
  let report: ReturnType<typeof vi.fn>;
  let catalogue: ReturnType<typeof vi.fn>;
  let facade: ReportsFacade;

  beforeEach(() => {
    report = vi.fn();
    catalogue = vi.fn();
    TestBed.configureTestingModule({
      providers: [{ provide: ReportsApi, useValue: { report, catalogue } }],
    });
    facade = TestBed.inject(ReportsFacade);
  });

  it('keeps the catalogue shown when a later read fails, and says it failed', async () => {
    catalogue.mockResolvedValueOnce([
      { key: 'takings', byEstablishment: true, periodic: true, columns: [] },
    ]);
    await facade.loadCatalogue('k1');
    catalogue.mockRejectedValueOnce(new Error('offline'));
    await facade.loadCatalogue('k1');

    expect(facade.catalogue()?.map((entry) => entry.key)).toEqual(['takings']);
    expect(facade.catalogueFailed()).toBe(true);
  });

  it('tells a report that is not there from a question refused and from a failure', async () => {
    report.mockRejectedValueOnce(new ReportGone());
    await facade.loadReport('k1', 'takings', QUERY);
    expect(facade.report().status).toBe('gone');

    report.mockRejectedValueOnce(new ReportRefused());
    await facade.loadReport('k1', 'takings', QUERY);
    expect(facade.report().status).toBe('refused');

    report.mockRejectedValueOnce(new Error('offline'));
    await facade.loadReport('k1', 'takings', QUERY);
    expect(facade.report().status).toBe('unavailable');
  });

  it('never shows an earlier question’s answer over a later one’s', async () => {
    let answerFirst: (value: ReportResult) => void = () => undefined;
    report.mockReturnValueOnce(new Promise<ReportResult>((resolve) => (answerFirst = resolve)));
    report.mockResolvedValueOnce(result('second'));
    const first = facade.loadReport('k1', 'takings', QUERY);
    await facade.loadReport('k1', 'takings', { ...QUERY, compare: 'year' });
    answerFirst(result('first'));
    await first;

    const shown = facade.report();
    expect(shown.status === 'ready' ? shown.result.key : null).toBe('second');
  });

  it('forgets the report shown, so the next opens on nothing of it', async () => {
    report.mockResolvedValueOnce(result('takings'));
    await facade.loadReport('k1', 'takings', QUERY);
    facade.forgetReport();
    expect(facade.report()).toEqual({ status: 'loading' });
  });
});
