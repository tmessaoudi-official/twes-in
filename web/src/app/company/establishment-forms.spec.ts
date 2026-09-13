// SPDX-License-Identifier: AGPL-3.0-or-later

import { buildFormGroup } from '../shared/form/form-builder';
import type { EstablishmentRow } from './company-types';
import {
  establishmentForm,
  establishmentFormValues,
  establishmentInput,
  seriesChanges,
  SERIES_FORM,
} from './establishment-forms';

const head: EstablishmentRow = {
  id: 'e1',
  code: '000',
  name: 'Acme',
  addressLine1: null,
  addressLine2: null,
  postalCode: '1000',
  city: null,
  phone: null,
  email: null,
  isDefault: true,
  codePattern: '^[0-9]{3}$',
};

describe('establishment forms', () => {
  it("checks a code against the shape the company's preset gives it", () => {
    const form = buildFormGroup(establishmentForm(head.codePattern), { name: 'Agence' });

    form.get('code')!.setValue('12');
    expect(form.get('code')!.valid).toBe(false);
    form.get('code')!.setValue('001');
    expect(form.get('code')!.valid).toBe(true);
  });

  it('accepts any code until the shape is known', () => {
    const form = buildFormGroup(establishmentForm(''), { name: 'Agence', code: 'A1' });

    expect(form.get('code')!.valid).toBe(true);
  });

  it('sends a blank line as nothing, and keeps what a row said', () => {
    const values = { ...establishmentFormValues(head), city: '  ', name: ' Siège ' };

    expect(establishmentInput(values)).toEqual({
      code: '000',
      name: 'Siège',
      addressLine1: null,
      addressLine2: null,
      postalCode: '1000',
      city: null,
      phone: null,
      email: null,
      isDefault: true,
    });
  });

  it('offers the three reset periods and never sends one the API does not know', () => {
    const reset = SERIES_FORM.sections[0]!.fields.find((field) => field.id === 'resetPeriod');

    expect(reset?.options?.map((option) => option.value)).toEqual(['yearly', 'monthly', 'never']);
    expect(seriesChanges({ format: 'F-{SEQ}', nextNumber: '12', resetPeriod: 'weekly' })).toEqual({
      format: 'F-{SEQ}',
      nextNumber: 12,
      resetPeriod: 'yearly',
    });
  });
});
