// SPDX-License-Identifier: AGPL-3.0-or-later

import type { CustomFieldDefinition } from '../shared/custom-fields/custom-fields-types';
import type { FormValues } from '../shared/form/form-types';
import {
  DEFINITIONS_LIST,
  definitionForm,
  definitionFormValues,
  definitionInput,
} from './custom-field-forms';

const sector: CustomFieldDefinition = {
  id: 'f1',
  entity: 'customer',
  key: 'sector',
  label: 'Secteur',
  type: 'choice',
  required: true,
  choices: ['retail', 'wholesale'],
  sortOrder: 1,
  isActive: true,
};

const ids = (definition: CustomFieldDefinition | null): string[] =>
  definitionForm(definition).sections.flatMap((section) => section.fields.map((field) => field.id));

describe('custom field forms', () => {
  it('lists the fields by position with their key, label, type and whether they are retired', () => {
    expect(DEFINITIONS_LIST.columns.map((column) => column.id)).toEqual([
      'key',
      'label',
      'type',
      'required',
      'status',
      'sortOrder',
    ]);
    expect(DEFINITIONS_LIST.defaultSort).toEqual({ column: 'sortOrder', direction: 'asc' });
    const status = DEFINITIONS_LIST.columns.find((column) => column.id === 'status');
    expect([status?.value(sector), status?.value({ ...sector, isActive: false })]).toEqual([
      'active',
      'retired',
    ]);
  });

  it('declares a new field with its key and type, and asks choices of a choice field only', () => {
    const fields = definitionForm(null).sections.flatMap((section) => section.fields);

    expect(fields.map((field) => field.id)).toEqual([
      'key',
      'label',
      'type',
      'choices',
      'required',
      'sortOrder',
    ]);
    expect(fields.find((field) => field.id === 'key')).toMatchObject({
      required: true,
      maxLength: 40,
      pattern: '[a-z][a-z0-9_]{0,39}',
    });
    expect(
      fields.find((field) => field.id === 'type')?.options?.map((option) => option.value),
    ).toEqual(['text', 'number', 'date', 'bool', 'choice']);
    expect(fields.find((field) => field.id === 'choices')).toMatchObject({
      kind: 'textarea',
      required: true,
      visibleWhen: { field: 'type', oneOf: ['choice'] },
    });
  });

  it("never offers to change a declared field's key or type, and lets it be retired", () => {
    expect(ids(sector)).toEqual(['label', 'choices', 'required', 'sortOrder', 'isActive']);
    expect(ids({ ...sector, type: 'text', choices: [] })).toEqual([
      'label',
      'required',
      'sortOrder',
      'isActive',
    ]);
  });

  it("sends one choice per line, and keeps a declared field's key and type whatever the form says", () => {
    expect(
      definitionInput(
        {
          ...definitionFormValues(null),
          key: ' sector ',
          label: ' Secteur ',
          type: 'choice',
          choices: ' retail \n\nwholesale\n',
          required: true,
          sortOrder: 2,
        },
        null,
      ),
    ).toEqual({
      entity: 'customer',
      key: 'sector',
      label: 'Secteur',
      type: 'choice',
      required: true,
      choices: ['retail', 'wholesale'],
      sortOrder: 2,
      isActive: true,
    });

    const declared = {
      entity: sector.entity,
      key: sector.key,
      label: sector.label,
      type: sector.type,
      required: sector.required,
      choices: sector.choices,
      sortOrder: sector.sortOrder,
      isActive: sector.isActive,
    };
    const values: FormValues = {
      ...definitionFormValues(sector),
      label: 'Activité',
      isActive: false,
    };
    expect(values['choices']).toBe('retail\nwholesale');
    expect(definitionInput({ ...values, key: 'renamed', type: 'text' }, sector)).toEqual({
      ...declared,
      label: 'Activité',
      isActive: false,
    });

    expect(
      definitionInput(
        { ...definitionFormValues(null), key: 'note', label: 'Note', choices: 'a' },
        null,
      ).choices,
    ).toEqual([]);
  });
});
