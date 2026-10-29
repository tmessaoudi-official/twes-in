// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  buildFormGroup,
  DuplicateFormField,
  fieldError,
  InvalidFormField,
  UnknownFormSection,
  valuesAsTyped,
  withCustomFields,
} from './form-builder';
import type { FieldValue, FormDescriptor, FormField } from './form-types';

const name: FormField = {
  id: 'name',
  label: 'c.name',
  kind: 'text',
  required: true,
  maxLength: 80,
  minLength: 2,
};
const email: FormField = { id: 'email', label: 'c.email', kind: 'email' };
const terms: FormField = {
  id: 'terms',
  label: 'c.terms',
  kind: 'number',
  min: 0,
  max: 120,
  defaultValue: 30,
};
const vat: FormField = { id: 'vat', label: 'c.vat', kind: 'text', pattern: '[A-Z0-9/]+' };
const currency: FormField = {
  id: 'currency',
  label: 'c.currency',
  kind: 'select',
  required: true,
  defaultValue: 'TND',
  options: [
    { value: 'TND', label: 'TND' },
    { value: 'EUR', label: 'EUR' },
  ],
};

const customer: FormDescriptor = {
  id: 'customer',
  sections: [
    { id: 'identity', title: 'c.identity', fields: [name, email, terms] },
    { id: 'billing', title: 'c.billing', fields: [vat, currency] },
  ],
};

describe('buildFormGroup', () => {
  it('builds one control per declared field, starting from the declared defaults', () => {
    expect(buildFormGroup(customer).getRawValue()).toEqual({
      name: '',
      email: '',
      terms: 30,
      vat: '',
      currency: 'TND',
    });
  });

  it('starts from the values it is given, and ignores values for fields nobody declared', () => {
    const group = buildFormGroup(customer, { name: 'Acme', terms: 45, ghost: 'boo' });

    expect(group.getRawValue()).toEqual({
      name: 'Acme',
      email: '',
      terms: 45,
      vat: '',
      currency: 'TND',
    });
    expect(group.contains('ghost')).toBe(false);
  });

  it('refuses a select that offers nothing to choose', () => {
    const broken: FormDescriptor = {
      id: 'broken',
      sections: [{ id: 's', title: 's', fields: [{ id: 'pick', label: 'p', kind: 'select' }] }],
    };

    expect(() => buildFormGroup(broken)).toThrow(InvalidFormField);
  });
});

const taxes: FormField = {
  id: 'taxes',
  label: 'c.taxes',
  kind: 'multiselect',
  options: [
    { value: 't1', label: 'TVA' },
    { value: 't2', label: 'FODEC' },
  ],
};
const needTaxes: FormField = { ...taxes, id: 'needTaxes', required: true };

describe('multiselect', () => {
  const group = buildFormGroup({
    id: 'tax',
    sections: [{ id: 's', title: 's', fields: [taxes, needTaxes] }],
  });
  const errorOf = (field: FormField, value: FieldValue) => {
    const control = group.controls[field.id];
    control.setValue(value);
    return fieldError(control, field);
  };

  it('starts as an empty list, never a blank string', () => {
    expect(
      buildFormGroup({
        id: 'tax',
        sections: [{ id: 's', title: 's', fields: [taxes] }],
      }).getRawValue(),
    ).toEqual({
      taxes: [],
    });
  });

  it('asks for at least one when it is required, and for none when it is not', () => {
    expect(errorOf(needTaxes, [])).toEqual({ key: 'form.errors.required' });
    expect(errorOf(needTaxes, ['t1'])).toBeNull();
    expect(errorOf(taxes, [])).toBeNull();
  });

  it('accepts only options it offers, each of them', () => {
    expect(errorOf(taxes, ['t1', 't2'])).toBeNull();
    expect(errorOf(taxes, ['t1', 'zz'])).toEqual({ key: 'form.errors.option' });
  });
});

describe('fieldError', () => {
  const errorOf = (field: FormField, value: FieldValue) => {
    const group = buildFormGroup(customer);
    const control = group.controls[field.id];
    control.setValue(value);
    return fieldError(control, field);
  };

  it('answers null for a valid value, and for an optional field left empty', () => {
    expect(errorOf(name, 'Acme')).toBeNull();
    expect(errorOf(email, '')).toBeNull();
    expect(errorOf(vat, '')).toBeNull();
  });

  it('asks for a required field', () => {
    expect(errorOf(name, '')).toEqual({ key: 'form.errors.required' });
    expect(errorOf(name, '   ')).toEqual({ key: 'form.errors.required' });
  });

  it('checks the shape of an email address', () => {
    expect(errorOf(email, 'not-an-address')).toEqual({ key: 'form.errors.email' });
  });

  it('names the limit a text length or a number crossed', () => {
    expect(errorOf(name, 'x')).toEqual({ key: 'form.errors.min_length', params: { min: 2 } });
    expect(errorOf(name, 'x'.repeat(81))).toEqual({
      key: 'form.errors.max_length',
      params: { max: 80 },
      count: '81 / 80',
    });
    expect(errorOf(terms, -1)).toEqual({ key: 'form.errors.min', params: { min: 0 } });
    expect(errorOf(terms, 121)).toEqual({ key: 'form.errors.max', params: { max: 120 } });
  });

  it('matches the pattern against the whole value', () => {
    expect(errorOf(vat, 'fr 12')).toEqual({ key: 'form.errors.pattern' });
    expect(errorOf({ ...vat, hint: 'c.vat_hint' }, 'fr 12')).toEqual({
      key: 'form.errors.pattern',
      hint: 'c.vat_hint',
    });
    expect(errorOf(vat, 'ok/but lower')).toEqual({ key: 'form.errors.pattern' });
    expect(errorOf(vat, '1234567A/A/M/000')).toBeNull();
  });

  it("says a field's own format sentence when its value is outside the pattern", () => {
    const phone: FormField = {
      ...vat,
      hint: 'c.vat_hint',
      patternError: 'import.rejections.invalid_phone',
    };
    expect(errorOf(phone, 'fr 12')).toEqual({ key: 'import.rejections.invalid_phone' });
    expect(errorOf(phone, '1234567A/A/M/000')).toBeNull();
  });

  // The sentence the API refuses the value with names its format and an example; the browser's own word does not.
  it("says an e-mail field's own format sentence before the browser's word for an address", () => {
    const address: FormField = {
      id: 'address',
      label: 'c.address',
      kind: 'email',
      pattern: '[a-z]+@[a-z]+(?:\\.[a-z]+)+',
      patternError: 'import.rejections.invalid_email',
    };
    const control = buildFormGroup({
      id: 'f',
      sections: [{ id: 's', title: 's', fields: [address] }],
    }).controls['address'];
    control.setValue('a@b..c');

    expect(control.hasError('email')).toBe(true);
    expect(fieldError(control, address)).toEqual({ key: 'import.rejections.invalid_email' });
  });

  it('accepts only one of the options a select offers', () => {
    expect(errorOf(currency, 'USD')).toEqual({ key: 'form.errors.option' });
    expect(errorOf(currency, 'EUR')).toBeNull();
  });

  // A paste from a page or a spreadsheet brings spaces and invisible marks at its ends, which nobody meant.
  it('judges what was typed without the spaces and invisible marks at its ends', () => {
    expect(errorOf(vat, ' 1234567A/A/M/000​ ')).toBeNull();
    expect(errorOf(email, ' contact@exemple.tn‎')).toBeNull();
    expect(errorOf(name, ' x ')).toEqual({ key: 'form.errors.min_length', params: { min: 2 } });
    expect(errorOf(name, `${'x'.repeat(80)}  `)).toBeNull();
    expect(errorOf(name, '​​')).toEqual({ key: 'form.errors.required' });
    // What stands between the ends is still judged.
    expect(errorOf(vat, ' 1234567A /A/M/000 ')).toEqual({ key: 'form.errors.pattern' });
  });
});

describe('the values a form hands on', () => {
  const code: FormField = { id: 'code', label: 'c.code', kind: 'text', readOnly: true };
  const notes: FormField = { id: 'notes', label: 'c.notes', kind: 'textarea' };
  const phone: FormField = { id: 'phone', label: 'c.phone', kind: 'tel' };
  const site: FormField = { id: 'site', label: 'c.site', kind: 'url' };
  const form: FormDescriptor = {
    id: 'f',
    sections: [
      { id: 's', title: 's', fields: [name, email, vat, code, notes, phone, site, terms] },
    ],
  };

  it('are what was typed without its ends, whichever way a screen reads them, while the field shows it as typed', () => {
    const group = buildFormGroup(form, {
      name: ' Acme ',
      email: '​contact@exemple.tn ',
      vat: ' 1234567A/A/M/000',
      notes: '\nPayable à 30 jours.\n',
      phone: ' +216 71 900 868 ',
      site: ' https://exemple.tn﻿',
      terms: 30,
    });

    expect(group.getRawValue()).toEqual({
      name: 'Acme',
      email: 'contact@exemple.tn',
      vat: '1234567A/A/M/000',
      code: '',
      notes: 'Payable à 30 jours.',
      phone: '+216 71 900 868',
      site: 'https://exemple.tn',
      terms: 30,
    });
    expect(group.controls['name'].getRawValue()).toBe('Acme');
    // Trimming as it is typed would eat the space before the next word.
    expect(group.controls['name'].value).toBe(' Acme ');
  });

  it('are kept as typed, ends included, for a form rebuilt over the one being typed in', () => {
    const group = buildFormGroup(form, { name: 'Rue ' });
    group.controls['code'].disable();

    expect(valuesAsTyped(group)).toEqual(
      expect.objectContaining({ name: 'Rue ', code: '', terms: 30 }),
    );
  });

  it('keep a read-only value as it stands', () => {
    expect(buildFormGroup(form, { code: ' FA-0001 ' }).getRawValue()['code']).toBe(' FA-0001 ');
  });
});

describe('withCustomFields', () => {
  it('appends the fields an installation configured to the section it names', () => {
    const extended = withCustomFields(customer, 'billing', [
      { id: 'custom.segment', label: 'Segment', kind: 'text' },
    ]);

    expect(extended.sections[1].fields.map((field) => field.id)).toEqual([
      'vat',
      'currency',
      'custom.segment',
    ]);
    expect(customer.sections[1].fields).toHaveLength(2);
  });

  it('refuses a field whose id is already declared in any section', () => {
    expect(() =>
      withCustomFields(customer, 'billing', [{ id: 'name', label: 'x', kind: 'text' }]),
    ).toThrow(DuplicateFormField);
  });

  it('refuses a section that does not exist', () => {
    expect(() => withCustomFields(customer, 'nowhere', [])).toThrow(UnknownFormSection);
  });
});
