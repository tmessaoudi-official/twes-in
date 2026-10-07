// SPDX-License-Identifier: AGPL-3.0-or-later

import { buildFormGroup } from '../shared/form/form-builder';
import { applyFilters } from '../shared/list/list-view';
import type { EstablishmentRow, NumberingSeriesRow } from './company-types';
import {
  establishmentForm,
  establishmentFormValues,
  establishmentInput,
  seriesChanges,
  seriesForm,
  SERIES_LIST,
  seriesList,
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
  codeLocked: false,
};

const fieldOf = (descriptor: ReturnType<typeof seriesForm>, id: string) =>
  descriptor.sections[0]!.fields.find((field) => field.id === id);

describe('establishment forms', () => {
  it('shows the next number before the format it is made from', () => {
    // « AV-{YYYY}-{SEQ:5} » alone told a shopkeeper nothing; the number it gives is what they read first.
    const ids = SERIES_LIST.columns.map((column) => column.id);
    expect(ids.indexOf('preview')).toBeLessThan(ids.indexOf('format'));
  });

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

  it('shows a code numbered documents carry without letting it change', () => {
    const locked = fieldOf(establishmentForm(head.codePattern, true), 'code');

    expect(locked?.readOnly).toBe(true);
    expect(locked?.hint).toBe('company.establishments.fields.code_locked_hint');
    expect(fieldOf(establishmentForm(head.codePattern), 'code')?.readOnly).toBeFalsy();
  });

  it('keeps where a sequence resumes once documents carry its numbers', () => {
    const frozen = fieldOf(seriesForm(true), 'nextNumber');

    expect(frozen?.readOnly).toBe(true);
    expect(frozen?.hint).toBe('company.numbering.fields.nextNumber_frozen_hint');
    expect(fieldOf(seriesForm(false), 'nextNumber')?.readOnly).toBeFalsy();
  });

  it('offers the three reset periods and never sends one the API does not know', () => {
    const reset = fieldOf(seriesForm(false), 'resetPeriod');

    expect(reset?.options?.map((option) => option.value)).toEqual(['yearly', 'monthly', 'never']);
    expect(seriesChanges({ format: 'F-{SEQ}', nextNumber: '12', resetPeriod: 'weekly' })).toEqual({
      format: 'F-{SEQ}',
      nextNumber: 12,
      resetPeriod: 'yearly',
    });
  });
});

describe('the series list', () => {
  it('offers the kinds of document and the establishments its series hold, several of each at once', () => {
    const series = (
      id: string,
      establishmentCode: string,
      documentType: string,
    ): NumberingSeriesRow => ({
      id,
      establishmentId: `e-${establishmentCode}`,
      establishmentCode,
      documentType,
      format: '{SEQ:5}',
      nextNumber: 1,
      resetPeriod: 'never',
      isDefault: true,
      numbered: false,
      preview: '00001',
    });
    const rows = [
      series('s1', '000', 'invoice'),
      series('s2', '000', 'delivery_note'),
      series('s3', '001', 'invoice'),
      series('s4', '001', 'credit_note'),
    ];
    const list = seriesList(rows);

    expect(list.filters?.map((filter) => [filter.id, filter.multiple])).toEqual([
      ['documentType', true],
      ['establishment', true],
    ]);
    expect(list.filters?.[0]?.options.map((option) => option.value)).toEqual([
      'credit_note',
      'delivery_note',
      'invoice',
    ]);
    expect(
      applyFilters(rows, list.filters ?? [], {
        documentType: 'invoice,credit_note',
        establishment: '001',
      }).map((row) => row.id),
    ).toEqual(['s3', 's4']);
  });
});
