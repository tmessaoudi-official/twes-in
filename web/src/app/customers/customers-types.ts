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
  /** Words found in the number, name, legal name, email, billing address or registration numbers; empty finds all. */
  q: string;
  /** Any of these, each filter's values OR'd and the filters AND'd (row 197). */
  kinds: readonly CustomerKind[];
  groupIds: readonly string[];
  /** Tax regime codes, among those of the company's fiscal preset. */
  regimes: readonly string[];
  isActive: boolean | null;
  /** The ends of the creation day interval, `createdAt.from` and `createdAt.to`, in the company's own calendar. */
  intervals: Readonly<Record<string, string>>;
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

/** What a line of a statement is: an invoice or a credit note on the day it was issued, a payment on the day it was made. */
export type StatementKind = 'invoice' | 'credit_note' | 'payment' | 'credit_transfer';

export interface StatementLine {
  /** YYYY-MM-DD. */
  day: string;
  kind: StatementKind;
  /** The document's number; a payment carries the number of the invoice it settles. */
  number: string;
  /** The invoice or credit note the line is, or the invoice a payment settles. */
  documentId: string;
  /** A payment's own reference when it has one. */
  reference: string | null;
  debit: string;
  credit: string;
  /** What the customer owed after this line. */
  balance: string;
}

/** A customer's account over a period (docs/SPEC.md § 7): amounts are the API's decimal strings at the currency's scale. */
export interface CustomerStatement {
  customerId: string;
  customerName: string;
  customerNumber: string;
  currency: string;
  currencyScale: number;
  /** The period as the API resolved it, YYYY-MM-DD. */
  from: string;
  to: string;
  openingBalance: string;
  totalDebit: string;
  totalCredit: string;
  closingBalance: string;
  /** What the customer may owe before a delivery warns; zero is no limit. */
  creditLimit: string;
  /** The closing balance is more than the limit: the API decides, in exact decimals. */
  overCreditLimit: boolean;
  /** What the customer has to their credit, money paid beyond their invoices; shown apart from the lines. */
  creditBalance: string;
  lines: StatementLine[];
}

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
