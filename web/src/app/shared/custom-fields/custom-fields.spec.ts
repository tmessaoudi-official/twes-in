// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  customFieldInput,
  customFieldValues,
  customFormFields,
  customListColumns,
} from './custom-fields';
import type { CustomFieldDefinition } from './custom-fields-types';

function field(
  partial: Partial<CustomFieldDefinition> & Pick<CustomFieldDefinition, 'key' | 'type'>,
): CustomFieldDefinition {
  return {
    id: `id-${partial.key}`,
    entity: 'customer',
    label: partial.key,
    required: false,
    choices: [],
    sortOrder: 0,
    isActive: true,
    ...partial,
  };
}

const sector = field({
  key: 'sector',
  label: 'Secteur',
  type: 'choice',
  required: true,
  choices: ['retail', 'wholesale'],
  sortOrder: 1,
});
const limit = field({ key: 'credit_limit', label: 'Plafond', type: 'number' });
const since = field({ key: 'client_since', label: 'Client depuis', type: 'date' });
const vip = field({ key: 'vip', label: 'VIP', type: 'bool', required: true });
const manager = field({ key: 'manager', label: 'Chargé', type: 'text' });
const legacy = field({ key: 'legacy', label: 'Ancien code', type: 'text', isActive: false });
const fields = [sector, limit, since, vip, manager, legacy];

describe('custom fields', () => {
  it('renders the active fields in their order, each as the control its type calls for', () => {
    const rendered = customFormFields(fields);

    expect(rendered.map((each) => [each.id, each.kind, each.label])).toEqual([
      ['custom__client_since', 'date', 'Client depuis'],
      ['custom__credit_limit', 'number', 'Plafond'],
      ['custom__manager', 'text', 'Chargé'],
      ['custom__vip', 'checkbox', 'VIP'],
      ['custom__sector', 'select', 'Secteur'],
    ]);
    expect(rendered.find((each) => each.id === 'custom__sector')).toMatchObject({
      required: true,
      options: [
        { value: 'retail', label: 'retail' },
        { value: 'wholesale', label: 'wholesale' },
      ],
    });
    expect(rendered.find((each) => each.id === 'custom__manager')?.maxLength).toBe(2000);
    // A ticked box is not what a required yes-or-no asks: unticked is an answer too.
    expect(rendered.find((each) => each.id === 'custom__vip')?.required).toBeFalsy();
  });

  it('offers an optional choice with a way to choose nothing', () => {
    const optional = customFormFields([{ ...sector, required: false }])[0];

    expect(optional?.options?.[0]).toEqual({ value: '', label: 'form.no_value' });
  });

  it('starts each field at what the record holds, empty otherwise', () => {
    expect(customFieldValues(fields, { sector: 'retail', vip: true, legacy: 'A-1' })).toEqual({
      custom__sector: 'retail',
      custom__credit_limit: null,
      custom__client_since: '',
      custom__vip: true,
      custom__manager: '',
    });
    expect(customFieldValues(fields, {})['custom__vip']).toBe(false);
  });

  it('sends what was filled in, typed, and leaves retired fields to the API', () => {
    expect(
      customFieldInput(fields, {
        custom__sector: 'wholesale',
        custom__credit_limit: '1500.5',
        custom__client_since: '',
        custom__vip: false,
        custom__manager: '  Leila ',
        custom__legacy: 'B-2',
      }),
    ).toEqual({ sector: 'wholesale', credit_limit: 1500.5, vip: false, manager: 'Leila' });
    expect(customFieldInput(fields, { custom__credit_limit: 12 })).toEqual({
      credit_limit: 12,
      vip: false,
    });
    expect(customFieldInput(fields, { custom__credit_limit: 'douze' })).toEqual({ vip: false });
  });

  it('adds one hidden column per active field, showing the value as text', () => {
    const columns = customListColumns(fields);

    expect(columns.map((column) => [column.id, column.label, column.defaultHidden])).toEqual([
      ['custom__client_since', 'Client depuis', true],
      ['custom__credit_limit', 'Plafond', true],
      ['custom__manager', 'Chargé', true],
      ['custom__vip', 'VIP', true],
      ['custom__sector', 'Secteur', true],
    ]);
    const row = { customFields: { sector: 'retail', credit_limit: 20, vip: true } };
    expect(columns.map((column) => column.value(row))).toEqual(['', 20, '', '✓', 'retail']);
  });
});
