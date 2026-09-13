// SPDX-License-Identifier: AGPL-3.0-or-later

import type { FieldKind, FormField, FormValues } from '../form/form-types';
import type { CellValue, ListColumn } from '../list/list-types';
import type {
  CustomFieldDefinition,
  CustomFieldType,
  CustomFieldValue,
} from './custom-fields-types';

/** The id prefix of a custom field in a form and of its column in a list. */
export const CUSTOM_FIELD_PREFIX = 'custom__';
/** The API refuses longer text; the form says so first. */
export const CUSTOM_TEXT_MAX = 2000;

const KINDS: Record<CustomFieldType, FieldKind> = {
  text: 'text',
  number: 'number',
  date: 'date',
  bool: 'checkbox',
  choice: 'select',
};

/** The fields a person fills in: the active ones, by position then key. Retired fields stay out of sight. */
function active(definitions: readonly CustomFieldDefinition[]): CustomFieldDefinition[] {
  return definitions
    .filter((definition) => definition.isActive)
    .sort((a, b) => a.sortOrder - b.sortOrder || a.key.localeCompare(b.key));
}

/**
 * The form fields a company's custom fields add, to join a screen's declared ones through withCustomFields. A
 * yes-or-no field is never required here: unticked is an answer, and the API asks only that one was given.
 */
export function customFormFields(definitions: readonly CustomFieldDefinition[]): FormField[] {
  return active(definitions).map((definition): FormField => {
    const field: FormField = {
      id: CUSTOM_FIELD_PREFIX + definition.key,
      label: definition.label,
      kind: KINDS[definition.type],
      required: definition.type !== 'bool' && definition.required,
    };
    if (definition.type === 'text') {
      field.maxLength = CUSTOM_TEXT_MAX;
    }
    if (definition.type === 'choice') {
      field.options = [
        ...(definition.required ? [] : [{ value: '', label: 'form.no_value' }]),
        ...definition.choices.map((choice) => ({ value: choice, label: choice })),
      ];
    }
    return field;
  });
}

/** Each custom field at what the record holds, empty when it holds nothing. */
export function customFieldValues(
  definitions: readonly CustomFieldDefinition[],
  stored: Readonly<Record<string, CustomFieldValue>>,
): FormValues {
  const values: FormValues = {};
  for (const definition of active(definitions)) {
    const value = stored[definition.key];
    const id = CUSTOM_FIELD_PREFIX + definition.key;
    switch (definition.type) {
      case 'bool':
        values[id] = value === true;
        break;
      case 'number':
        values[id] = typeof value === 'number' ? value : null;
        break;
      default:
        values[id] = typeof value === 'string' ? value : '';
    }
  }
  return values;
}

/**
 * The values to send: what was filled in, typed, an empty field left out. Retired fields are not sent; the API
 * carries over what the record holds for them.
 */
export function customFieldInput(
  definitions: readonly CustomFieldDefinition[],
  values: FormValues,
): Record<string, CustomFieldValue> {
  const input: Record<string, CustomFieldValue> = {};
  for (const definition of active(definitions)) {
    const raw = values[CUSTOM_FIELD_PREFIX + definition.key];
    if (definition.type === 'bool') {
      input[definition.key] = raw === true;
    } else if (definition.type === 'number') {
      const number = typeof raw === 'number' ? raw : Number(String(raw ?? '').trim() || NaN);
      if (Number.isFinite(number)) {
        input[definition.key] = number;
      }
    } else {
      const text = typeof raw === 'string' ? raw.trim() : '';
      if (text !== '') {
        input[definition.key] = text;
      }
    }
  }
  return input;
}

/** One list column per active custom field, hidden until a person places it. */
export function customListColumns<Row extends { customFields: Record<string, CustomFieldValue> }>(
  definitions: readonly CustomFieldDefinition[],
): ListColumn<Row>[] {
  return active(definitions).map((definition) => ({
    id: CUSTOM_FIELD_PREFIX + definition.key,
    label: definition.label,
    value: (row: Row): CellValue => cell(row.customFields[definition.key]),
    sortable: true,
    filterable: true,
    defaultHidden: true,
  }));
}

function cell(value: CustomFieldValue | undefined): CellValue {
  if (value === true) return '✓';
  if (value === undefined || value === false) return '';
  return value;
}
