// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The settings engine seen from the web: one row per setting of a chain, as the API resolved it for the signed-in
 * person in their working company (docs/SPEC.md § 3 Settings). Built by settings-api.ts from the generated types.
 */
export type SettingChain = 'presentation' | 'parties' | 'articles';

export type SettingLevel =
  | 'platform'
  | 'company'
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
  overridableLevels: SettingLevel[];
  writableLevels: SettingLevel[];
  choices: string[];
  min: number | string | null;
  max: number | string | null;
  maxLength: number | null;
  /** A regular expression a text value must match, in the API's delimited form (`/^…$/`). */
  pattern: string | null;
}

export type SettingsError = 'not_found' | 'invalid' | 'network';

/** Whom a parties-chain read or change is for, below the company: one customer, or one customer group. */
export type PartySubject = { customerId: string } | { customerGroupId: string };

/** Whom an articles-chain read or change is for, below the company: one product, or one product category. */
export type ArticleSubject = { productId: string } | { productCategoryId: string };

/** A read or a change names one subject at most, of either chain. */
export type SettingSubject = PartySubject | ArticleSubject;
