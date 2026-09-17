// SPDX-License-Identifier: AGPL-3.0-or-later

import { declaredPayment, paymentForm, paymentFormValues, today } from './subscription-forms';

describe('the payment form', () => {
  it('asks for what the API requires, and for the amount in the shape it accepts', () => {
    const fields = paymentForm().sections[0].fields;
    const byId = Object.fromEntries(fields.map((field) => [field.id, field]));

    expect(fields.filter((field) => field.required === true).map((field) => field.id)).toEqual([
      'amount',
      'method',
      'paidOn',
    ]);
    // Three decimals at most, as every amount in this API.
    expect(new RegExp(`^${byId['amount'].pattern}$`).test('600.000')).toBe(true);
    expect(new RegExp(`^${byId['amount'].pattern}$`).test('600.0000')).toBe(false);
    expect(byId['method'].options?.map((option) => option.value)).toEqual([
      'cash',
      'transfer',
      'cheque',
      'other',
    ]);
  });

  it('starts on cash, today, with nothing filled in', () => {
    expect(paymentFormValues()).toEqual({
      amount: '',
      method: 'cash',
      paidOn: today(),
      reference: '',
      note: '',
    });
    // A day in the browser's own zone, not a UTC one: the person filling it in is in the company's.
    expect(today(new Date(2026, 0, 1, 0, 30))).toBe('2026-01-01');
  });

  it('carries the currency from the subscription, never from what was typed', () => {
    expect(
      declaredPayment(
        { amount: ' 600.000 ', method: 'transfer', paidOn: '2026-09-16', reference: '', note: '' },
        'TND',
      ),
    ).toEqual({
      amount: '600.000',
      currency: 'TND',
      method: 'transfer',
      paidOn: '2026-09-16',
      reference: null,
      note: null,
    });
  });

  it('falls back to cash for a method it does not know, which the API refuses anyway', () => {
    const payment = declaredPayment(
      { amount: '10', method: 'barter', paidOn: '2026-09-16', reference: 'R', note: 'N' },
      'EUR',
    );

    expect(payment.method).toBe('cash');
    expect(payment.reference).toBe('R');
    expect(payment.note).toBe('N');
  });
});
