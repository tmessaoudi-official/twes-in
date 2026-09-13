// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  buildFormGroup,
  DuplicateFormField,
  fieldError,
  InvalidFormField,
  UnknownFormSection,
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
    });
    expect(errorOf(terms, -1)).toEqual({ key: 'form.errors.min', params: { min: 0 } });
    expect(errorOf(terms, 121)).toEqual({ key: 'form.errors.max', params: { max: 120 } });
  });

  it('matches the pattern against the whole value', () => {
    expect(errorOf(vat, 'fr 12')).toEqual({ key: 'form.errors.pattern' });
    expect(errorOf(vat, 'ok/but lower')).toEqual({ key: 'form.errors.pattern' });
    expect(errorOf(vat, '1234567A/A/M/000')).toBeNull();
  });

  it('accepts only one of the options a select offers', () => {
    expect(errorOf(currency, 'USD')).toEqual({ key: 'form.errors.option' });
    expect(errorOf(currency, 'EUR')).toBeNull();
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
