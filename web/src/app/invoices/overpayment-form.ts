// SPDX-License-Identifier: AGPL-3.0-or-later

import type { FieldValue, FormDescriptor, FormValues } from '../shared/form/form-types';
import type { OverpaymentInput } from './invoices-types';

const AMOUNT_PATTERN = '(0|[1-9][0-9]{0,11})([.][0-9]{1,3})?';

/**
 * Money the customer paid beyond an invoice paid in full, kept to their credit (a « trop-perçu »). An advance before any
 * invoice is not this: it takes a deposit invoice (docs/fiscal TN.md and FR.md, § 2b).
 */
export function overpaymentForm(): FormDescriptor {
  return {
    id: 'invoice-overpayment',
    sections: [
      {
        id: 'overpayment',
        title: 'invoices.overpayment.section',
        fields: [
          { id: 'date', label: 'invoices.overpayment.date', kind: 'date', required: true },
          {
            id: 'amount',
            label: 'invoices.overpayment.amount',
            kind: 'decimal',
            required: true,
            maxLength: 16,
            pattern: AMOUNT_PATTERN,
          },
          {
            id: 'reference',
            label: 'invoices.overpayment.reference',
            kind: 'text',
            maxLength: 120,
            hint: 'invoices.overpayment.reference_hint',
          },
          {
            id: 'notes',
            label: 'invoices.overpayment.notes',
            kind: 'textarea',
            maxLength: 2000,
            span: 2,
          },
        ],
      },
    ],
  };
}

export function overpaymentValues(today: string): FormValues {
  return { date: today, amount: '', reference: '', notes: '' };
}

export function overpaymentInput(values: FormValues): OverpaymentInput {
  const text = (value: FieldValue | undefined): string | null => {
    const trimmed = String(value ?? '').trim();
    return trimmed === '' ? null : trimmed;
  };
  return {
    date: String(values['date'] ?? '').trim(),
    amount: String(values['amount'] ?? '').trim(),
    reference: text(values['reference']),
    notes: text(values['notes']),
  };
}
