// SPDX-License-Identifier: AGPL-3.0-or-later

import type { FormDescriptor, FormValues } from '../shared/form/form-types';
import { PAYMENT_METHODS, type DeclaredPayment, type PaymentMethod } from './subscription-types';

const FIELDS = 'licensing.payment.fields';

/** What a company fills in to say it paid. The amount's shape is the API's own: at most three decimals. */
export function paymentForm(): FormDescriptor {
  return {
    id: 'declare-payment',
    sections: [
      {
        id: 'payment',
        title: 'licensing.payment.section',
        description: 'licensing.payment.section_hint',
        fields: [
          {
            id: 'amount',
            label: `${FIELDS}.amount`,
            kind: 'text',
            required: true,
            pattern: '(0|[1-9]\\d{0,9})(\\.\\d{1,3})?',
            hint: `${FIELDS}.amount_hint`,
          },
          {
            id: 'method',
            label: `${FIELDS}.method`,
            kind: 'select',
            required: true,
            defaultValue: 'cash',
            options: PAYMENT_METHODS.map((method) => ({
              value: method,
              label: `licensing.payment.methods.${method}`,
            })),
          },
          { id: 'paidOn', label: `${FIELDS}.paidOn`, kind: 'date', required: true },
          {
            id: 'reference',
            label: `${FIELDS}.reference`,
            kind: 'text',
            maxLength: 64,
            hint: `${FIELDS}.reference_hint`,
          },
          {
            id: 'note',
            label: `${FIELDS}.note`,
            kind: 'textarea',
            maxLength: 500,
            span: 2,
          },
        ],
      },
    ],
  };
}

/** Today in the browser's own timezone, which is the company's for the person filling this in. */
export function today(now: Date = new Date()): string {
  const month = `${now.getMonth() + 1}`.padStart(2, '0');
  const day = `${now.getDate()}`.padStart(2, '0');

  return `${now.getFullYear()}-${month}-${day}`;
}

export function paymentFormValues(): FormValues {
  return { amount: '', method: 'cash', paidOn: today(), reference: '', note: '' };
}

/** The form's values as the API takes them; the currency is the subscription's, never typed. */
export function declaredPayment(values: FormValues, currency: string): DeclaredPayment {
  return {
    amount: text(values['amount']),
    currency,
    method: method(values['method']),
    paidOn: text(values['paidOn']),
    reference: optional(values['reference']),
    note: optional(values['note']),
  };
}

function method(value: unknown): PaymentMethod {
  const known = PAYMENT_METHODS.find((one) => one === value);

  return known ?? 'cash';
}

function text(value: unknown): string {
  return typeof value === 'string' ? value.trim() : '';
}

function optional(value: unknown): string | null {
  const trimmed = text(value);

  return trimmed === '' ? null : trimmed;
}
