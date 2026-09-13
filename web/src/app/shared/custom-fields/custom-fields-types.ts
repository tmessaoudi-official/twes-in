// SPDX-License-Identifier: AGPL-3.0-or-later

/** The kinds of record a company may add fields to; products and documents join with their goals. */
export type CustomFieldEntity = 'customer';

export type CustomFieldType = 'text' | 'number' | 'date' | 'bool' | 'choice';
export const CUSTOM_FIELD_TYPES: readonly CustomFieldType[] = [
  'text',
  'number',
  'date',
  'bool',
  'choice',
];

/** A value a record holds for one custom field, as the API sends and takes it. */
export type CustomFieldValue = string | number | boolean;

/** A field a company added to one kind of record; its key and type never change once declared. */
export interface CustomFieldDefinition {
  id: string;
  entity: CustomFieldEntity;
  key: string;
  label: string;
  type: CustomFieldType;
  required: boolean;
  /** The values a choice field offers, in order; empty for any other type. */
  choices: string[];
  sortOrder: number;
  /** A retired field is no longer shown; the values records hold for it stay. */
  isActive: boolean;
}

export type CustomFieldInput = Omit<CustomFieldDefinition, 'id'>;
