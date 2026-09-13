// SPDX-License-Identifier: AGPL-3.0-or-later

import type { FieldValue, FormDescriptor, FormField, FormValues } from '../shared/form/form-types';
import type { SettingChain, SettingRow } from '../shared/settings/settings-types';

/** The chains a company sets defaults in, in the order the page shows them. */
export const COMPANY_CHAINS: readonly SettingChain[] = ['parties', 'articles', 'presentation'];

/** A text allowed to be longer than this gets several lines across both columns. */
const LONG_TEXT = 200;

const DECIMAL = '-?\\d{1,15}(\\.\\d{1,6})?';

export interface SettingChange {
  key: string;
  value: FieldValue;
}

/** Form control names cannot carry the dots of a setting key. */
export const fieldIdOf = (key: string): string => key.replaceAll('.', '__');

/** A setting the company level may hold, that the person may set there, and that a form can show. */
function editable(row: SettingRow): boolean {
  return row.type !== 'json' && row.writableLevels.includes('company');
}

/**
 * The company's defaults as one form, rendered from the definitions the API returns (docs/SPEC.md § 3 Settings):
 * one section per chain, one field per setting, its kind from the setting's type and its rules from the
 * setting's constraints. A section with nothing to set is left out.
 */
export function companySettingsForm(rows: readonly SettingRow[]): FormDescriptor {
  return {
    id: 'company-settings',
    sections: COMPANY_CHAINS.map((chain) => ({
      id: chain,
      title: `settings.chains.${chain}`,
      fields: rows.filter((row) => row.chain === chain && editable(row)).map(fieldOf),
    })).filter((section) => section.fields.length > 0),
  };
}

/** Each field starts at the company's own value, else the default: a person's own choice is not the company's. */
export function companySettingsValues(rows: readonly SettingRow[]): FormValues {
  const values: FormValues = {};
  for (const row of rows.filter(editable)) {
    values[fieldIdOf(row.key)] = companyValue(row) as FieldValue;
  }
  return values;
}

/** The settings whose company value the submitted form changed, and their new values. */
export function changedSettings(rows: readonly SettingRow[], values: FormValues): SettingChange[] {
  return rows.filter(editable).flatMap((row) => {
    const id = fieldIdOf(row.key);
    return Object.hasOwn(values, id) && values[id] !== companyValue(row)
      ? [{ key: row.key, value: values[id] }]
      : [];
  });
}

/** The settings the company holds a value for; a reset returns each to the level above. */
export function companyOverrides(rows: readonly SettingRow[]): SettingRow[] {
  return rows.filter((row) => editable(row) && row.levels.some((held) => held.level === 'company'));
}

/** What the company says, else what the platform says, else the declared default; a role's or a person's value is not the company's. */
function companyValue(row: SettingRow): unknown {
  const held = (level: string) => row.levels.find((candidate) => candidate.level === level);
  return (held('company') ?? held('platform'))?.value ?? row.defaultValue;
}

function fieldOf(row: SettingRow): FormField {
  const id = fieldIdOf(row.key);
  const label = row.labelKey;
  switch (row.type) {
    case 'bool':
      return { id, label, kind: 'checkbox' };
    case 'int':
      return { id, label, kind: 'number', required: true, ...bounds(row) };
    case 'decimal':
    case 'money':
      return { id, label, kind: 'text', required: true, pattern: DECIMAL };
    case 'enum':
      return {
        id,
        label,
        kind: 'select',
        required: true,
        options: row.choices.map((choice) => ({
          value: choice,
          label: `settings.choices.${row.key}.${choice}`,
        })),
      };
    case 'colour':
      return { id, label, kind: 'colour', required: true };
    default:
      return textField(id, label, row);
  }
}

function bounds(row: SettingRow): Pick<FormField, 'min' | 'max'> {
  return {
    ...(row.min === null ? {} : { min: Number(row.min) }),
    ...(row.max === null ? {} : { max: Number(row.max) }),
  };
}

/** A text with an empty default may be left empty; a pattern or a long limit decides the field's shape. */
function textField(id: string, label: string, row: SettingRow): FormField {
  const pattern = row.pattern === null ? undefined : unanchored(row.pattern);
  const long = pattern === undefined && (row.maxLength ?? Number.POSITIVE_INFINITY) > LONG_TEXT;
  return {
    id,
    label,
    kind: long ? 'textarea' : 'text',
    required: row.defaultValue !== '',
    ...(long ? { span: 2 as const } : {}),
    ...(row.maxLength === null ? {} : { maxLength: row.maxLength }),
    ...(pattern === undefined ? {} : { pattern }),
  };
}

/** `/^[A-Z]{3}$/` becomes `[A-Z]{3}`: the form anchors a string pattern at both ends itself. */
function unanchored(delimited: string): string {
  const match = /^\/\^?(.*?)\$?\/[a-z]*$/s.exec(delimited);
  return match?.[1] ?? delimited;
}
