// SPDX-License-Identifier: AGPL-3.0-or-later

import { buildFormGroup, InvalidFormField } from './form-builder';
import { applicableValues, applyVisibility, fieldApplies } from './form-visibility';
import type { FormDescriptor } from './form-types';

const tax: FormDescriptor = {
  id: 'tax',
  sections: [
    {
      id: 'main',
      title: 't.main',
      fields: [
        {
          id: 'family',
          label: 't.family',
          kind: 'select',
          required: true,
          defaultValue: 'vat',
          options: [
            { value: 'vat', label: 't.vat' },
            { value: 'stamp', label: 't.stamp' },
          ],
        },
        {
          id: 'rate',
          label: 't.rate',
          kind: 'text',
          required: true,
          visibleWhen: { field: 'family', oneOf: ['vat'] },
        },
        {
          id: 'amount',
          label: 't.amount',
          kind: 'text',
          required: true,
          visibleWhen: { field: 'family', oneOf: ['stamp'] },
        },
        { id: 'isDefault', label: 't.default', kind: 'checkbox' },
      ],
    },
  ],
};

describe('form visibility', () => {
  it('shows a field under any writing of nothing in a decimal field, and under nothing else', () => {
    const priced: FormDescriptor = {
      id: 'priced',
      sections: [
        {
          id: 'main',
          title: 't.main',
          fields: [
            { id: 'price', label: 't.price', kind: 'decimal' },
            {
              id: 'meant',
              label: 't.meant',
              kind: 'checkbox',
              visibleWhen: { field: 'price', zero: true },
            },
          ],
        },
      ],
    };
    const meant = priced.sections[0].fields[1];
    for (const price of ['0', '0.000', '00.0', '-0', '.0', '0.']) {
      expect(fieldApplies(meant, { price }), price).toBe(true);
    }
    for (const price of ['', '0.001', '10', '1.000', '-1', 'abc', '.']) {
      expect(fieldApplies(meant, { price }), price).toBe(false);
    }
  });

  it('keeps a read-only field disabled and still hands its value over', () => {
    const establishment: FormDescriptor = {
      id: 'establishment',
      sections: [
        {
          id: 'main',
          title: 't.main',
          fields: [
            { id: 'code', label: 't.code', kind: 'text', required: true, readOnly: true },
            { id: 'name', label: 't.name', kind: 'text', required: true },
          ],
        },
      ],
    };
    const form = buildFormGroup(establishment, { code: '000', name: 'Siège' });

    applyVisibility(establishment, form);

    expect(form.controls['code'].disabled).toBe(true);
    expect(form.controls['name'].enabled).toBe(true);
    expect(applicableValues(establishment, form)).toEqual({ code: '000', name: 'Siège' });
  });

  it('applies a field without a condition always, and a conditional one only for its values', () => {
    const [family, rate, amount] = tax.sections[0].fields;
    expect(fieldApplies(family, {})).toBe(true);
    expect(fieldApplies(rate, { family: 'vat' })).toBe(true);
    expect(fieldApplies(amount, { family: 'vat' })).toBe(false);
    expect(fieldApplies(amount, { family: null })).toBe(false);
  });

  it('disables what does not apply, so a hidden required field does not block the form', () => {
    const form = buildFormGroup(tax);
    applyVisibility(tax, form);

    form.controls['rate'].setValue('19');

    expect(form.controls['amount'].disabled).toBe(true);
    expect(form.valid).toBe(true);
  });

  it('follows a change of the controlling field', () => {
    const form = buildFormGroup(tax);
    applyVisibility(tax, form);

    form.controls['family'].setValue('stamp');
    applyVisibility(tax, form);

    expect(form.controls['rate'].disabled).toBe(true);
    expect(form.controls['amount'].enabled).toBe(true);
    expect(form.valid).toBe(false);
  });

  it('keeps what was typed in a field that was hidden, and submits only what applies', () => {
    const form = buildFormGroup(tax);
    applyVisibility(tax, form);
    form.controls['rate'].setValue('19');

    form.controls['family'].setValue('stamp');
    applyVisibility(tax, form);
    form.controls['amount'].setValue('1');

    expect(form.controls['rate'].value).toBe('19');
    expect(applicableValues(tax, form)).toEqual({ family: 'stamp', amount: '1', isDefault: false });
  });

  it('keeps every field disabled in a read-only form, whatever applies', () => {
    const form = buildFormGroup(tax);
    applyVisibility(tax, form);

    applyVisibility(tax, form, true);

    expect(Object.values(form.controls).every((control) => control.disabled)).toBe(true);

    applyVisibility(tax, form);
    expect(form.controls['rate'].enabled).toBe(true);
    expect(form.controls['amount'].disabled).toBe(true);
  });

  it('applies a field under two conditions only while both hold, so a hidden choice cannot show its own field', () => {
    const day = {
      id: 'monthDay',
      label: 't.day',
      kind: 'number' as const,
      visibleWhen: {
        field: 'anchor',
        oneOf: ['date'],
        and: { field: 'unit', oneOf: ['month', 'year'] },
      },
    };

    expect(fieldApplies(day, { unit: 'month', anchor: 'date' })).toBe(true);
    expect(fieldApplies(day, { unit: 'week', anchor: 'date' })).toBe(false);
    expect(fieldApplies(day, { unit: 'month', anchor: 'last_day' })).toBe(false);
  });

  it('refuses a second condition on a field the form does not declare', () => {
    const broken: FormDescriptor = {
      id: 'broken',
      sections: [
        {
          id: 'main',
          title: 't.main',
          fields: [
            { id: 'anchor', label: 't.anchor', kind: 'text' },
            {
              id: 'rate',
              label: 't.rate',
              kind: 'text',
              visibleWhen: { field: 'anchor', oneOf: ['x'], and: { field: 'nope', oneOf: ['y'] } },
            },
          ],
        },
      ],
    };

    expect(() => buildFormGroup(broken)).toThrow(InvalidFormField);
  });

  it('refuses a condition on a field the form does not declare', () => {
    const broken: FormDescriptor = {
      id: 'broken',
      sections: [
        {
          id: 'main',
          title: 't.main',
          fields: [
            {
              id: 'rate',
              label: 't.rate',
              kind: 'text',
              visibleWhen: { field: 'nope', oneOf: ['x'] },
            },
          ],
        },
      ],
    };

    expect(() => buildFormGroup(broken)).toThrow(InvalidFormField);
  });
});

describe('checkbox fields', () => {
  const consent: FormDescriptor = {
    id: 'consent',
    sections: [
      {
        id: 'main',
        title: 'c.main',
        fields: [
          { id: 'active', label: 'c.active', kind: 'checkbox', defaultValue: true },
          { id: 'terms', label: 'c.terms', kind: 'checkbox', required: true },
        ],
      },
    ],
  };

  it('starts unticked unless a default says otherwise', () => {
    const form = buildFormGroup(consent);

    expect(form.controls['active'].value).toBe(true);
    expect(form.controls['terms'].value).toBe(false);
  });

  it('treats a required checkbox as one that must be ticked', () => {
    const form = buildFormGroup(consent);
    expect(form.controls['terms'].hasError('required')).toBe(true);

    form.controls['terms'].setValue(true);

    expect(form.valid).toBe(true);
  });
});
