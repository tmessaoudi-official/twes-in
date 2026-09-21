// SPDX-License-Identifier: AGPL-3.0-or-later

import type { FieldValue, FormDescriptor, FormField, FormValues } from '../form/form-types';
import type { SettingChain, SettingLevel, SettingRow } from './settings-types';

/** Each chain's levels, most general first, the order the API walks them in (docs/SPEC.md § 3 Settings). */
const CHAIN_LEVELS: Record<SettingChain, readonly SettingLevel[]> = {
  parties: ['platform', 'company', 'customer_group', 'customer', 'document'],
  articles: ['platform', 'company', 'product_category', 'product', 'document_line'],
  presentation: ['platform', 'company', 'role', 'user'],
  // A floor belongs to a company, and nothing below one draws a plan, so the chain stops there.
  venue: ['platform', 'company'],
};

/** A text allowed to be longer than this gets several lines across both columns. */
const LONG_TEXT = 200;

const DECIMAL = '-?\\d{1,15}(\\.\\d{1,6})?';

export interface SettingChange {
  key: string;
  value: FieldValue;
}

/** Which settings form to build: the level its values are stored at, and the chains it shows. */
export interface LevelForm {
  id: string;
  level: SettingLevel;
  chains: readonly SettingChain[];
  /** Shows every setting the level may hold, for a form someone may read but not change. */
  readOnly?: boolean;
}

/** Form control names cannot carry the dots of a setting key. */
export const fieldIdOf = (key: string): string => key.replaceAll('.', '__');

/** A setting the level may hold and a form can show; unless read-only, one the person may set there. */
function shown(row: SettingRow, level: SettingLevel, readOnly: boolean): boolean {
  return (
    row.type !== 'json' &&
    row.overridableLevels.includes(level) &&
    (readOnly || row.writableLevels.includes(level))
  );
}

/**
 * The settings of one level as a form, rendered from the definitions the API returns: one section per chain, one
 * field per setting, its kind from the setting's type and its rules from the setting's constraints. A section with
 * nothing to show is left out.
 */
export function settingsForm(rows: readonly SettingRow[], form: LevelForm): FormDescriptor {
  const readOnly = form.readOnly ?? false;
  return {
    id: form.id,
    sections: form.chains
      .map((chain) => ({
        id: chain,
        title: `settings.chains.${chain}`,
        fields: rows
          .filter((row) => row.chain === chain && shown(row, form.level, readOnly))
          .map(fieldOf),
      }))
      .filter((section) => section.fields.length > 0),
  };
}

/** Each setting the level may hold, at the level's own value, else what the nearest level above says, else the default. */
export function settingsValues(rows: readonly SettingRow[], level: SettingLevel): FormValues {
  const values: FormValues = {};
  for (const row of rows.filter((each) => shown(each, level, true))) {
    values[fieldIdOf(row.key)] = valueAt(row, level) as FieldValue;
  }
  return values;
}

/** The settings whose value at the level the submitted form changed, and their new values. */
export function changedSettingsAt(
  rows: readonly SettingRow[],
  values: FormValues,
  level: SettingLevel,
): SettingChange[] {
  return rows
    .filter((row) => shown(row, level, false))
    .flatMap((row) => {
      const id = fieldIdOf(row.key);
      return Object.hasOwn(values, id) && values[id] !== valueAt(row, level)
        ? [{ key: row.key, value: values[id] }]
        : [];
    });
}

/** The settings the level holds a value of its own for; a reset returns each to the level above. */
export function overridesAt(
  rows: readonly SettingRow[],
  level: SettingLevel,
  readOnly = false,
): SettingRow[] {
  return rows.filter(
    (row) => shown(row, level, readOnly) && row.levels.some((held) => held.level === level),
  );
}

/** What the level says, else the nearest level above it; a more specific level's value is not this level's. */
function valueAt(row: SettingRow, level: SettingLevel): unknown {
  const order = CHAIN_LEVELS[row.chain];
  const reach = order.slice(0, order.indexOf(level) + 1);
  const nearest = row.levels
    .filter((held) => reach.includes(held.level))
    .sort((a, b) => order.indexOf(b.level) - order.indexOf(a.level))[0];
  return nearest === undefined ? row.defaultValue : nearest.value;
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
      return { id, label, kind: 'decimal', required: true, pattern: DECIMAL };
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
