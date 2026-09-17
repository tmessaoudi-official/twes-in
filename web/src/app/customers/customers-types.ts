// SPDX-License-Identifier: AGPL-3.0-or-later

import type { CustomFieldValue } from '../shared/custom-fields/custom-fields-types';

/** Why the API refused, as the customers screens translate it. */
export type CustomersError =
  'network' | 'not_found' | 'number_taken' | 'name_taken' | 'in_use' | 'invalid';

export type CustomerKind = 'company' | 'individual';
export const CUSTOMER_KINDS: readonly CustomerKind[] = ['company', 'individual'];

/** What the API sorts customers by. */
export type CustomerSortKey = 'number' | 'name' | 'kind' | 'customerGroup' | 'city' | 'isActive';

/** One page of the customers list as the API searches, narrows and sorts it (docs/SPEC.md § 7, lists at scale). */
export interface CustomerSearch {
  /** Numbered from 1. */
  page: number;
  itemsPerPage: number;
  /** Words found in the number, name, legal name, email or billing city; empty finds every customer. */
  q: string;
  kind: CustomerKind | null;
  isActive: boolean | null;
  order: { key: CustomerSortKey; direction: 'asc' | 'desc' } | null;
}

export type TaxFamily = 'vat' | 'levy' | 'stamp' | 'withholding';

export interface CustomerAddress {
  line1: string | null;
  line2: string | null;
  postalCode: string | null;
  city: string | null;
  countryCode: string | null;
}

export interface CustomerRow {
  id: string;
  number: string;
  kind: CustomerKind;
  customerGroupId: string | null;
  taxRegime: string;
  name: string;
  legalName: string | null;
  identifiers: Record<string, string>;
  email: string | null;
  phone: string | null;
  website: string | null;
  billingAddress: CustomerAddress;
  /** Null when goods go to the billing address. */
  shippingAddress: CustomerAddress | null;
  defaultTaxComponentIds: string[];
  /** A percentage as a decimal string, "5.000". */
  defaultDiscountRate: string | null;
  notes: string | null;
  isActive: boolean;
  /** Values by the company's custom field keys; a retired field's value stays here. */
  customFields: Record<string, CustomFieldValue>;
}

export type CustomerInput = Omit<CustomerRow, 'id'>;

export interface CustomerGroupRow {
  id: string;
  name: string;
  description: string | null;
  customerCount: number;
}

export type CustomerGroupInput = Pick<CustomerGroupRow, 'name' | 'description'>;

export interface ContactRow {
  id: string;
  firstName: string | null;
  lastName: string | null;
  email: string | null;
  phone: string | null;
  role: string | null;
  isPrimary: boolean;
}

export type ContactInput = Omit<ContactRow, 'id'>;

export interface IdentifierOption {
  key: string;
  label: string;
  /** Anchored by the preset, without delimiters. */
  pattern: string;
  requiredForBusiness: boolean;
}

export interface RegimeOption {
  code: string;
  label: string;
  excludedFamilies: TaxFamily[];
}

export interface TaxOption {
  id: string;
  code: string;
  name: string;
  family: TaxFamily;
}

/** What the customer form offers, as the company's preset and fiscal setup say. */
export interface CustomerOptions {
  countryCode: string;
  identifiers: IdentifierOption[];
  regimes: RegimeOption[];
  taxes: TaxOption[];
}
