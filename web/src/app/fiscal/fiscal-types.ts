// SPDX-License-Identifier: AGPL-3.0-or-later

/** The four tax families a component belongs to; the kind of arithmetic follows from the family. */
export type TaxFamily = 'vat' | 'levy' | 'stamp' | 'withholding';

export const TAX_FAMILIES: readonly TaxFamily[] = ['vat', 'levy', 'stamp', 'withholding'];

/** One tax a company charges, as the API returns it. Decimals stay strings: they are money and rates. */
export interface TaxComponentRow {
  readonly id: string;
  readonly code: string;
  readonly name: string;
  readonly kind: string;
  readonly family: TaxFamily;
  readonly rate: string | null;
  readonly amount: string | null;
  readonly threshold: string | null;
  readonly entersVatBase: boolean;
  readonly isDefault: boolean;
  readonly isActive: boolean;
  readonly exemptionMention: string | null;
  readonly sortOrder: number;
}

/** What a person fills in to add a tax; the code and the family are fixed once it exists. */
export interface TaxComponentInput {
  readonly code: string;
  readonly name: string;
  readonly family: TaxFamily;
  readonly rate: string | null;
  readonly amount: string | null;
  readonly threshold: string | null;
  readonly entersVatBase: boolean;
  readonly isDefault: boolean;
  readonly isActive: boolean;
  readonly exemptionMention: string | null;
  readonly sortOrder: number;
}

export interface UnitRow {
  readonly id: string;
  readonly code: string;
  readonly name: string;
  readonly decimals: number;
  readonly isActive: boolean;
  readonly sortOrder: number;
}

export interface UnitInput {
  readonly code: string;
  readonly name: string;
  readonly decimals: number;
  readonly isActive: boolean;
  readonly sortOrder: number;
}

/** A regime a customer may be under, read-only, labelled by the API in the reader's language. */
export interface CustomerTaxRegimeRow {
  readonly code: string;
  readonly label: string;
  readonly excludedFamilies: readonly TaxFamily[];
  readonly hasMention: boolean;
  readonly sortOrder: number;
}

/**
 * Why the API refused. "not_found" covers a company or a row that does not exist and one the caller has nothing to
 * do with, which the API answers identically on purpose.
 */
export type FiscalError = 'code_taken' | 'invalid' | 'not_found' | 'network';
