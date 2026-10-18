// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  addressOf,
  columnLabel,
  documentLink,
  drillLink,
  presetOf,
  presetPeriod,
  queryOf,
} from './report-view';
import type { ReportEntry } from './reports-types';

const PERIODIC: ReportEntry = {
  key: 'takings',
  byEstablishment: true,
  periodic: true,
  columns: [],
};
const TODAY: ReportEntry = {
  key: 'aged-balance',
  byEstablishment: true,
  periodic: false,
  columns: [],
};

describe('report view', () => {
  it('writes each preset from the company’s today, a short month and a leap year included', () => {
    expect(presetPeriod('this_month', '2026-10-18')).toEqual({
      from: '2026-10-01',
      to: '2026-10-31',
    });
    expect(presetPeriod('last_month', '2026-03-31')).toEqual({
      from: '2026-02-01',
      to: '2026-02-28',
    });
    expect(presetPeriod('last_month', '2028-03-05')).toEqual({
      from: '2028-02-01',
      to: '2028-02-29',
    });
    expect(presetPeriod('last_month', '2026-01-10')).toEqual({
      from: '2025-12-01',
      to: '2025-12-31',
    });
    expect(presetPeriod('this_year', '2026-10-18')).toEqual({
      from: '2026-01-01',
      to: '2026-12-31',
    });
    expect(presetPeriod('last_year', '2026-10-18')).toEqual({
      from: '2025-01-01',
      to: '2025-12-31',
    });
  });

  it('names the preset a period is, and any other period custom', () => {
    expect(presetOf({ from: '2026-09-01', to: '2026-09-30' }, '2026-10-18')).toBe('last_month');
    expect(presetOf({ from: '2026-10-01', to: '2026-10-31' }, '2026-10-18')).toBe('this_month');
    expect(presetOf({ from: '2026-10-01', to: '2026-10-18' }, '2026-10-18')).toBe('custom');
  });

  it('opens a report of a period on this month against the month before, and a report of today on nothing else', () => {
    expect(queryOf(new Map(), PERIODIC, '2026-10-18')).toEqual({
      from: '2026-10-01',
      to: '2026-10-31',
      compare: 'previous',
      establishment: null,
    });
    expect(
      queryOf(
        new Map([
          ['from', '2026-01-01'],
          ['compare', 'year'],
        ]),
        TODAY,
        '2026-10-18',
      ),
    ).toEqual({ from: null, to: null, compare: 'none', establishment: null });
  });

  it('keeps what the address says when it is a period and a comparison, and drops what it is not', () => {
    const asked = new Map([
      ['from', '2026-09-01'],
      ['to', '2026-09-15'],
      ['compare', 'year'],
      ['establishment', 'e1'],
    ]);
    expect(queryOf(asked, PERIODIC, '2026-10-18')).toEqual({
      from: '2026-09-01',
      to: '2026-09-15',
      compare: 'year',
      establishment: 'e1',
    });
    const wrong = new Map([
      ['from', '2026-02-30'],
      ['to', '2026-09-15'],
      ['compare', 'decade'],
    ]);
    expect(queryOf(wrong, PERIODIC, '2026-10-18')).toEqual({
      from: '2026-10-01',
      to: '2026-10-31',
      compare: 'previous',
      establishment: null,
    });
    expect(
      queryOf(
        new Map([
          ['from', '2026-09-20'],
          ['to', '2026-09-01'],
        ]),
        PERIODIC,
        '2026-10-18',
      ).from,
    ).toBe('2026-10-01');
  });

  it('writes the address back, leaving out what is not asked', () => {
    expect(
      addressOf({ from: '2026-09-01', to: '2026-09-30', compare: 'none', establishment: null }),
    ).toEqual({
      from: '2026-09-01',
      to: '2026-09-30',
      compare: 'none',
      establishment: null,
    });
    expect(addressOf({ from: null, to: null, compare: 'none', establishment: 'e1' })).toEqual({
      from: null,
      to: null,
      compare: null,
      establishment: 'e1',
    });
  });

  it('labels a column by its key, an age bracket by its stem with its days', () => {
    expect(columnLabel({ key: 'net', kind: 'amount', params: {} })).toEqual({
      key: 'reports.columns.net',
      params: {},
    });
    expect(columnLabel({ key: 'late_2', kind: 'amount', params: { from: 31, to: 60 } })).toEqual({
      key: 'reports.columns.late',
      params: { from: 31, to: 60 },
    });
    expect(columnLabel({ key: 'late_last', kind: 'amount', params: { after: 90 } })).toEqual({
      key: 'reports.columns.late_last',
      params: { after: 90 },
    });
  });

  it('opens a row in its list with the list’s own filters, several values joined as the list reads them', () => {
    expect(
      drillLink({
        list: 'invoices',
        filters: {
          customer: 'c1',
          status: ['issued', 'partially_paid'],
          'issueDate.from': '2026-09-01',
        },
      }),
    ).toEqual({
      commands: ['/invoices'],
      queryParams: {
        customer: 'c1',
        status: 'issued,partially_paid',
        'issueDate.from': '2026-09-01',
      },
    });
    expect(drillLink({ list: 'stock-movements', filters: { product: 'p1' } })?.commands).toEqual([
      '/stock/movements',
    ]);
    expect(drillLink({ list: 'activity', filters: {} })?.commands).toEqual(['/company/activity']);
    expect(drillLink({ list: 'expenses', filters: {} })?.commands).toEqual(['/expenses']);
  });

  it('opens no list it does not know, and none for a row whose documents are the report’s own', () => {
    expect(drillLink({ list: 'quotes', filters: {} })).toBeNull();
    expect(drillLink({ list: null, filters: {} })).toBeNull();
  });

  it('opens each document on its own page', () => {
    const document = { id: 'd1', number: 'F-1', party: null, day: '2026-09-01', amount: '1.000' };
    expect(documentLink({ ...document, kind: 'invoice' })).toEqual(['/invoices', 'd1']);
    expect(documentLink({ ...document, kind: 'expense' })).toEqual(['/expenses', 'd1']);
    expect(documentLink({ ...document, kind: 'cheque' })).toBeNull();
  });
});
