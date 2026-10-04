// SPDX-License-Identifier: AGPL-3.0-or-later

import { instrumentForm, instrumentInput, instrumentValues } from './instruments-forms';

describe('the instrument form', () => {
  const fields = instrumentForm().sections.flatMap((section) => section.fields);

  it('asks the kind, the amount and the due day, and the bank and number besides', () => {
    expect(fields.map((field) => [field.id, field.kind, field.required ?? false])).toEqual([
      ['kind', 'select', true],
      ['amount', 'decimal', true],
      ['dueOn', 'date', true],
      ['bank', 'text', false],
      ['number', 'text', false],
    ]);
    expect(fields[0]?.options?.map((option) => option.value)).toEqual(['check', 'draft']);
    expect(() => JSON.stringify(instrumentForm())).not.toThrow();
  });

  it('starts as a cheque for what is free, due today', () => {
    expect(instrumentValues('2026-10-04', '1178.100')).toEqual({
      kind: 'check',
      amount: '1178.100',
      dueOn: '2026-10-04',
      bank: '',
      number: '',
    });
  });

  it("sends what was typed trimmed, a decimal comma as a point is the field's and an empty text as none", () => {
    expect(
      instrumentInput({
        kind: 'draft',
        amount: ' 250.5 ',
        dueOn: '2026-12-01',
        bank: ' BT ',
        number: '',
      }),
    ).toEqual({ kind: 'draft', amount: '250.5', dueOn: '2026-12-01', bank: 'BT', number: null });
    expect(instrumentInput({ kind: 'cheque', amount: '1', dueOn: '2026-12-01' }).kind).toBe(
      'check',
    );
  });
});
