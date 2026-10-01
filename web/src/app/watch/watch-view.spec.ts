// SPDX-License-Identifier: AGPL-3.0-or-later

import { formatAmount, formatDay } from '../shared/i18n/format';
import { watchRowView } from './watch-view';

const figures = {
  amount: (value: string, scale: number | null) => formatAmount(value, scale, 'fr-FR', 'auto'),
  day: (value: string) => formatDay(value, 'fr-FR', 'auto'),
};

describe('watchRowView', () => {
  it('writes the figures of a late customer as the screen writes figures', () => {
    const view = watchRowView(
      {
        kind: 'invoices.late_customer',
        subjectId: 'c1',
        params: {
          customer: 'Carthage',
          invoices: '2',
          amount: '1190.000',
          currency: 'TND',
          days: 40,
        },
      },
      3,
      figures,
    );

    expect(view.id).toBe('3');
    expect(view.cells['customer']).toBe('Carthage');
    expect(view.cells['invoices']).toBe(2);
    expect(String(view.cells['amount']).replace(/\s/g, '')).toBe('1190,000TND');
    expect(view.cells['days']).toBe(40);
  });

  it('writes a lot quantity as a person counts it and its date in the locale', () => {
    const view = watchRowView(
      {
        kind: 'stock.lot_expiring',
        subjectId: 'p1',
        params: {
          product: 'Colle',
          lot: 'L-1',
          quantity: '3.000',
          expiresOn: '2026-10-05',
          days: -2,
        },
      },
      0,
      figures,
    );

    expect(view.cells['quantity']).toBe('3');
    expect(view.cells['expiresOn']).toBe('05/10/2026');
    expect(view.cells['days']).toBe(-2);
  });

  it('leaves a cell out when the API sent no such figure', () => {
    const view = watchRowView(
      { kind: 'k', subjectId: null, params: { product: 'Colle' } },
      0,
      figures,
    );

    expect(Object.keys(view.cells)).toEqual(['product']);
  });
});
