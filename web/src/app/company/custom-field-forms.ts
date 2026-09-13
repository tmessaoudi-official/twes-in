// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  CUSTOM_FIELD_TYPES,
  type CustomFieldDefinition,
  type CustomFieldInput,
  type CustomFieldType,
} from '../shared/custom-fields/custom-fields-types';
import type { FormDescriptor, FormField, FormValues } from '../shared/form/form-types';
import type { ListDescriptor } from '../shared/list/list-types';

const FIELDS = 'company.custom_fields.fields';
/** The API's rule for a key, anchored by the form. */
const KEY_PATTERN = '[a-z][a-z0-9_]{0,39}';

export const DEFINITIONS_LIST: ListDescriptor<CustomFieldDefinition> = {
  id: 'company-custom-fields',
  rowId: (row) => row.id,
  pageSizes: [25, 50, 100],
  defaultSort: { column: 'sortOrder', direction: 'asc' },
  columns: [
    {
      id: 'key',
      label: `${FIELDS}.key`,
      value: (row) => row.key,
      sortable: true,
      filterable: true,
      hideable: false,
    },
    {
      id: 'label',
      label: `${FIELDS}.label`,
      value: (row) => row.label,
      sortable: true,
      filterable: true,
    },
    { id: 'type', label: `${FIELDS}.type`, value: (row) => row.type, sortable: true },
    {
      id: 'required',
      label: `${FIELDS}.required`,
      value: (row) => (row.required ? 1 : 0),
      sortable: true,
    },
    {
      id: 'status',
      label: `${FIELDS}.isActive`,
      value: (row) => (row.isActive ? 'active' : 'retired'),
      sortable: true,
    },
    {
      id: 'sortOrder',
      label: `${FIELDS}.sortOrder`,
      value: (row) => row.sortOrder,
      sortable: true,
      align: 'end',
    },
  ],
};

/**
 * The form to declare a field, or to revise a declared one. A declared field's key and type are not offered: they
 * never change, because customers store values under that key in that type.
 */
export function definitionForm(definition: CustomFieldDefinition | null): FormDescriptor {
  const choices: FormField = {
    id: 'choices',
    label: `${FIELDS}.choices`,
    kind: 'textarea',
    required: true,
    hint: `${FIELDS}.choices_hint`,
    span: 2,
    ...(definition === null ? { visibleWhen: { field: 'type', oneOf: ['choice'] } } : {}),
  };
  const fields: FormField[] = [
    ...(definition === null
      ? [
          {
            id: 'key',
            label: `${FIELDS}.key`,
            kind: 'text',
            required: true,
            maxLength: 40,
            pattern: KEY_PATTERN,
            hint: `${FIELDS}.key_hint`,
          } satisfies FormField,
        ]
      : []),
    { id: 'label', label: `${FIELDS}.label`, kind: 'text', required: true, maxLength: 80 },
    ...(definition === null
      ? [
          {
            id: 'type',
            label: `${FIELDS}.type`,
            kind: 'select',
            required: true,
            options: CUSTOM_FIELD_TYPES.map((type) => ({
              value: type,
              label: `company.custom_fields.types.${type}`,
            })),
          } satisfies FormField,
        ]
      : []),
    ...(definition === null || definition.type === 'choice' ? [choices] : []),
    { id: 'required', label: `${FIELDS}.required`, kind: 'checkbox' },
    { id: 'sortOrder', label: `${FIELDS}.sortOrder`, kind: 'number', min: 0, max: 10000 },
    ...(definition === null
      ? []
      : [
          {
            id: 'isActive',
            label: `${FIELDS}.isActive`,
            kind: 'checkbox',
            hint: `${FIELDS}.isActive_hint`,
          } satisfies FormField,
        ]),
  ];
  return {
    id: 'custom-field',
    sections: [{ id: 'field', title: 'company.custom_fields.section', fields }],
  };
}

/** A new field starts as optional text at the first position; a declared one at what it says. */
export function definitionFormValues(definition: CustomFieldDefinition | null): FormValues {
  return {
    key: definition?.key ?? '',
    label: definition?.label ?? '',
    type: definition?.type ?? 'text',
    choices: definition?.choices.join('\n') ?? '',
    required: definition?.required ?? false,
    sortOrder: definition?.sortOrder ?? 0,
    isActive: definition?.isActive ?? true,
  };
}

/** The field as the API takes it: one choice per non-empty line, and a declared field's own key and type. */
export function definitionInput(
  values: FormValues,
  definition: CustomFieldDefinition | null,
): CustomFieldInput {
  const type: CustomFieldType =
    definition?.type ?? CUSTOM_FIELD_TYPES.find((each) => each === values['type']) ?? 'text';
  return {
    entity: definition?.entity ?? 'customer',
    key: definition?.key ?? String(values['key'] ?? '').trim(),
    label: String(values['label'] ?? '').trim(),
    type,
    required: values['required'] === true,
    choices:
      type === 'choice'
        ? String(values['choices'] ?? '')
            .split('\n')
            .map((choice) => choice.trim())
            .filter((choice) => choice !== '')
        : [],
    sortOrder: Number(values['sortOrder'] ?? 0) || 0,
    isActive: definition === null ? true : values['isActive'] === true,
  };
}
