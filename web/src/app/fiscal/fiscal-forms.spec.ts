// SPDX-License-Identifier: AGPL-3.0-or-later

import { buildFormGroup } from '../shared/form/form-builder';
import { applicableValues, applyVisibility } from '../shared/form/form-visibility';
import type { FormDescriptor } from '../shared/form/form-types';
import {
  TAX_CREATE_FORM,
  taxFormValues,
  taxInput,
  taxReviseForm,
  UNIT_CREATE_FORM,
  UNIT_REVISE_FORM,
  unitFormValues,
  unitInput,
} from './fiscal-forms';
import type { TaxComponentRow, TaxFamily, UnitRow } from './fiscal-types';

const fieldIds = (descriptor: FormDescriptor): string[] =>
  descriptor.sections.flatMap((section) => section.fields.map((field) => field.id));

const stamp: TaxComponentRow = {
  id: 's1',
  code: 'TIMBRE',
  name: 'Droit de timbre',
  kind: 'fixed_document',
  family: 'stamp',
  rate: null,
  amount: '1.000',
  threshold: null,
  entersVatBase: false,
  isDefault: true,
  isActive: true,
  exemptionMention: null,
  sortOrder: 50,
};

describe('fiscal forms', () => {
  it('builds every form it declares', () => {
    for (const descriptor of [TAX_CREATE_FORM, UNIT_CREATE_FORM, UNIT_REVISE_FORM]) {
      expect(() => buildFormGroup(descriptor)).not.toThrow();
    }
  });

  // docs/SPEC.md § 7, 2026-09-19 21:55: a rate, a fixed amount and a threshold show and take the locale's decimal separator.
  it('asks every value of a tax as a decimal', () => {
    const decimals = TAX_CREATE_FORM.sections
      .flatMap((section) => section.fields)
      .filter((field) => field.kind === 'decimal')
      .map((field) => field.id);
    expect(decimals).toEqual(['rate', 'amount', 'threshold']);
  });

  it.each<[TaxFamily, string[]]>([
    ['vat', ['rate']],
    ['levy', ['rate', 'entersVatBase']],
    ['stamp', ['amount']],
    ['withholding', ['rate', 'threshold']],
  ])('offers a %s tax only its own value fields when adding one', (family, expected) => {
    const form = buildFormGroup(TAX_CREATE_FORM, { family });
    applyVisibility(TAX_CREATE_FORM, form);

    const applicable = Object.keys(applicableValues(TAX_CREATE_FORM, form));
    const valueFields = ['rate', 'amount', 'threshold', 'entersVatBase'];
    expect(applicable.filter((id) => valueFields.includes(id))).toEqual(expected);
  });

  it.each<[TaxFamily, string[]]>([
    ['vat', ['rate']],
    ['levy', ['rate', 'entersVatBase']],
    ['stamp', ['amount']],
    ['withholding', ['rate', 'threshold']],
  ])(
    'offers a %s tax only its own value fields when revising one, and never its code or family',
    (family, expected) => {
      const ids = fieldIds(taxReviseForm(family));

      expect(
        ids.filter((id) => ['rate', 'amount', 'threshold', 'entersVatBase'].includes(id)),
      ).toEqual(expected);
      expect(ids).not.toContain('code');
      expect(ids).not.toContain('family');
      expect(ids).toContain('isActive');
    },
  );

  it('sends a blank or absent value as null, and keeps the decimals as strings', () => {
    expect(
      taxInput({
        code: 'TVA12',
        family: 'vat',
        name: ' TVA 12 % ',
        rate: ' 12 ',
        amount: '',
        sortOrder: 35,
        isDefault: true,
      }),
    ).toEqual({
      code: 'TVA12',
      family: 'vat',
      name: ' TVA 12 % ',
      rate: '12',
      amount: null,
      threshold: null,
      entersVatBase: false,
      isDefault: true,
      isActive: true,
      exemptionMention: null,
      sortOrder: 35,
    });
  });

  it('revises a tax under its own code and family, whatever the values say', () => {
    const input = taxInput(
      { ...taxFormValues(stamp), code: 'OTHER', family: 'vat', amount: '1.500' },
      stamp,
    );

    expect(input.code).toBe('TIMBRE');
    expect(input.family).toBe('stamp');
    expect(input.amount).toBe('1.500');
  });

  it('round-trips a tax through its revision form', () => {
    expect(taxInput(taxFormValues(stamp), stamp)).toEqual({
      code: stamp.code,
      name: stamp.name,
      family: stamp.family,
      rate: stamp.rate,
      amount: stamp.amount,
      threshold: stamp.threshold,
      entersVatBase: stamp.entersVatBase,
      isDefault: stamp.isDefault,
      isActive: stamp.isActive,
      exemptionMention: stamp.exemptionMention,
      sortOrder: stamp.sortOrder,
    });
  });

  it('round-trips a unit through its revision form, keeping its code', () => {
    const hour: UnitRow = {
      id: 'u1',
      code: 'HUR',
      name: 'Heure',
      decimals: 2,
      isActive: false,
      sortOrder: 20,
    };
    expect(unitInput(unitFormValues(hour), 'HUR')).toEqual({
      code: 'HUR',
      name: 'Heure',
      decimals: 2,
      isActive: false,
      sortOrder: 20,
    });
  });
});
