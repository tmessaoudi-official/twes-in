// SPDX-License-Identifier: AGPL-3.0-or-later

import type { FieldValue } from '../form/form-types';

/**
 * The settings engine seen from the web: one row per setting of a chain, as the API resolved it for the signed-in
 * person in their working company (docs/SPEC.md § 3 Settings). Built by settings-api.ts from the generated types.
 */
export type SettingChain = 'presentation' | 'parties' | 'articles' | 'venue';

export type SettingLevel =
  | 'platform'
  | 'company'
  | 'establishment'
  | 'customer_group'
  | 'customer'
  | 'document'
  | 'product_category'
  | 'product'
  | 'document_line'
  | 'role'
  | 'user';

export type SettingType =
  'bool' | 'int' | 'decimal' | 'text' | 'enum' | 'money' | 'colour' | 'json';

export interface SettingLevelValue {
  level: SettingLevel;
  value: unknown;
}

export interface SettingRow {
  key: string;
  chain: SettingChain;
  type: SettingType;
  labelKey: string;
  module: string;
  defaultValue: unknown;
  value: unknown;
  /** null while the declared default is in force */
  source: SettingLevel | null;
  /** what each level of the chain holds, most general first */
  levels: SettingLevelValue[];
  /** the version of each level's stored row, a value no longer applying included; a level absent holds no row */
  versions: Partial<Record<SettingLevel, number>>;
  overridableLevels: SettingLevel[];
  writableLevels: SettingLevel[];
  choices: string[];
  min: number | string | null;
  max: number | string | null;
  maxLength: number | null;
  /** A regular expression a text value must match, in the API's delimited form (`/^…$/`). */
  pattern: string | null;
  /**
   * The reason a value outside the pattern is refused with (`import.rejections.<reason>`): the field says the API's
   * own sentence before it saves. Absent where the row was built without it.
   */
  patternReason?: string | null;
}

/** One key of a save, with the version its row held at the level when read, 0 for none. */
export interface VersionedSettingChange {
  key: string;
  value: FieldValue;
  version: number;
}

/** `record_changed`: a key of the save was saved, created or reset since it was read, and nothing was kept. */
/** What a parties setting holds across a company; `parties` is null for someone who may not read customers. */
export interface SettingValuesInUse {
  company: unknown;
  parties: readonly unknown[] | null;
}

export type SettingsError = 'not_found' | 'invalid' | 'record_changed' | 'network';

/** Whom a parties-chain read or change is for, below the company: one customer, or one customer group. */
export type PartySubject = { customerId: string } | { customerGroupId: string };

/**
 * Whom an articles-chain read or change is for, below the company: one product, one product category, one establishment,
 * or one role, whose values the person setting the company's defaults reads as that role sees them.
 */
export type ArticleSubject =
  | { productId: string }
  | { productCategoryId: string }
  | { establishmentId: string }
  | { roleId: string };

/** A read or a change names one subject at most, of either chain. */
export type SettingSubject = PartySubject | ArticleSubject;
