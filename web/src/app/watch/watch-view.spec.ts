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

  it('writes an unsent invitation with its address, its role as the members page names it and its days', () => {
    const say = (key: string): string => (key === 'roles.member' ? 'membre' : key);
    const shipped = watchRowView(
      {
        kind: 'members.invitation_unsent',
        subjectId: 'i1',
        params: {
          email: 'sami@example.tn',
          role: 'member',
          roleBuiltIn: 1,
          invitedOn: '2026-10-06',
          expiresOn: '2026-10-13',
        },
      },
      0,
      figures,
      say,
    );
    expect([
      shipped.cells['email'],
      shipped.cells['role'],
      shipped.cells['invitedOn'],
      shipped.cells['expiresOn'],
    ]).toEqual(['sami@example.tn', 'Membre', '06/10/2026', '13/10/2026']);

    const own = watchRowView(
      {
        kind: 'members.invitation_unsent',
        subjectId: 'i2',
        params: { email: 'a@b.tn', role: 'Comptable', roleBuiltIn: 0, invitedOn: '2026-10-06' },
      },
      1,
      figures,
      say,
    );
    expect(own.cells['role']).toBe('Comptable');
  });

  it('writes a receipt whose cost is to complete with its place and the day it arrived', () => {
    const view = watchRowView(
      {
        kind: 'stock.receipt_cost_to_complete',
        subjectId: 'm1',
        params: {
          productId: 'p1',
          product: 'Portable',
          reference: 'ART-1',
          quantity: '10.000',
          location: 'Réserve',
          receivedOn: '2026-10-06',
        },
      },
      0,
      figures,
    );

    expect(view.cells).toEqual({
      product: 'Portable',
      reference: 'ART-1',
      quantity: '10',
      location: 'Réserve',
      receivedOn: '06/10/2026',
    });
  });

  it("writes a cheque fallen due with its kind said in the screen's language and its day count", () => {
    const view = watchRowView(
      {
        kind: 'invoices.instruments_due',
        subjectId: 'i1',
        params: {
          customer: 'Carthage',
          invoice: 'FAC-2026-10-00007',
          kind: 'check',
          number: 'CHQ-1',
          bank: 'BT',
          amount: '250.500',
          currency: 'TND',
          days: 3,
        },
      },
      0,
      figures,
      (key) => `«${key}»`,
    );

    expect(view.cells['invoice']).toBe('FAC-2026-10-00007');
    expect(view.cells['kind']).toBe('«invoices.instruments.kinds.check»');
    expect(view.cells['number']).toBe('CHQ-1');
    expect(view.cells['bank']).toBe('BT');
    expect(String(view.cells['amount']).replace(/\s/g, '')).toBe('250,500TND');
    expect(view.cells['days']).toBe(3);
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
