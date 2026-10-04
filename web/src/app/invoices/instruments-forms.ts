// SPDX-License-Identifier: AGPL-3.0-or-later

import type { FieldValue, FormDescriptor, FormValues } from '../shared/form/form-types';
import { INSTRUMENT_KINDS, type InstrumentInput } from './instruments-types';

const AMOUNT_PATTERN = '(0|[1-9][0-9]{0,11})([.][0-9]{1,3})?';

/** Receiving a cheque or a traite: what it is, how much, when it falls due, the bank and its number. */
export function instrumentForm(): FormDescriptor {
  return {
    id: 'invoice-instrument',
    sections: [
      {
        id: 'instrument',
        title: 'invoices.instruments.receive_title',
        fields: [
          {
            id: 'kind',
            label: 'invoices.instruments.kind',
            kind: 'select',
            required: true,
            options: INSTRUMENT_KINDS.map((kind) => ({
              value: kind,
              label: `invoices.instruments.kinds.${kind}`,
            })),
          },
          {
            id: 'amount',
            label: 'invoices.instruments.amount',
            kind: 'decimal',
            required: true,
            maxLength: 16,
            pattern: AMOUNT_PATTERN,
            hint: 'invoices.instruments.amount_hint',
          },
          {
            id: 'dueOn',
            label: 'invoices.instruments.due_on',
            kind: 'date',
            required: true,
            hint: 'invoices.instruments.due_on_hint',
          },
          { id: 'bank', label: 'invoices.instruments.bank', kind: 'text', maxLength: 80 },
          { id: 'number', label: 'invoices.instruments.number', kind: 'text', maxLength: 64 },
        ],
      },
    ],
  };
}

/** A new instrument starts as a cheque for what is still free to cover, due today. */
export function instrumentValues(today: string, free: string): FormValues {
  return { kind: 'check', amount: free, dueOn: today, bank: '', number: '' };
}

export function instrumentInput(values: FormValues): InstrumentInput {
  return {
    kind: INSTRUMENT_KINDS.find((kind) => kind === values['kind']) ?? 'check',
    amount: text(values['amount']) ?? '',
    dueOn: text(values['dueOn']) ?? '',
    bank: text(values['bank']),
    number: text(values['number']),
  };
}

function text(value: FieldValue | undefined): string | null {
  const trimmed = String(value ?? '').trim();
  return trimmed === '' ? null : trimmed;
}
