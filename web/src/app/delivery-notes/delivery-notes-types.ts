// SPDX-License-Identifier: AGPL-3.0-or-later

import type { StatusTone } from '../shared/theme/accent-theme';

/** Why the API refused, as the delivery notes screens translate it. */
export type DeliveryNotesError = 'network' | 'not_found' | 'conflict' | 'invalid';

export type DeliveryNoteStatus = 'draft' | 'validated' | 'delivered' | 'cancelled' | 'invoiced';
/** The sorts the API answers; the total is worked out per row and is not a column it can order by. */
export type DeliveryNoteSortKey = 'number' | 'customer' | 'issueDate' | 'deliveryDate' | 'status';

/** One page of the delivery notes list as the API searches, narrows and sorts it (docs/SPEC.md § 7). */
export interface DeliveryNoteSearch {
  /** Numbered from 1. */
  page: number;
  itemsPerPage: number;
  /** Words found in the number, the customer's reference, or the customer the note recorded; empty finds all. */
  q: string;
  status: DeliveryNoteStatus | null;
  customerId: string | null;
  order: { key: DeliveryNoteSortKey; direction: 'asc' | 'desc' } | null;
}

export const DELIVERY_NOTE_STATUSES: readonly DeliveryNoteStatus[] = [
  'draft',
  'validated',
  'delivered',
  'cancelled',
  'invoiced',
];

/**
 * A status's colour on its badge: a draft is quiet, a validated note under way, a delivered one waits to be invoiced,
 * an invoiced one is done and a cancelled one withdrawn.
 */
export const DELIVERY_NOTE_STATUS_TONES: Readonly<Record<DeliveryNoteStatus, StatusTone>> = {
  draft: 'neutral',
  validated: 'info',
  delivered: 'warning',
  invoiced: 'success',
  cancelled: 'danger',
};

export type TaxFamily = 'vat' | 'levy' | 'stamp' | 'withholding';

export interface PostalAddress {
  line1: string | null;
  line2: string | null;
  postalCode: string | null;
  city: string | null;
  countryCode: string | null;
}

export interface DeliveryNoteLine {
  productId: string | null;
  description: string;
  /** A decimal string, "2" or "1.5", never finer than its unit counts. */
  quantity: string;
  unitId: string;
  /** A decimal string with up to four decimals. */
  unitPriceNet: string;
  taxComponentIds: string[];
  /** The line's net amount at the currency's scale, computed by the API on every read. */
  net: string;
}

export type DeliveryNoteLineInput = Omit<DeliveryNoteLine, 'net'>;

export interface TaxTotal {
  code: string;
  rate: string;
  base: string;
  amount: string;
}

export interface DeliveryNoteRow {
  id: string;
  /** Null until the note is validated. */
  number: string | null;
  status: DeliveryNoteStatus;
  customerId: string;
  establishmentId: string | null;
  /** The customer's name as the note was validated with it; null for a draft, which names today's customer. */
  customerName: string | null;
  issueDate: string | null;
  deliveryDate: string | null;
  deliveryAddress: PostalAddress;
  customerReference: string | null;
  remarksPrinted: string | null;
  notesInternal: string | null;
  lines: DeliveryNoteLine[];
  subtotalNet: string;
  taxes: TaxTotal[];
  totalTax: string;
  total: string;
}

export interface DeliveryNoteInput {
  customerId: string;
  establishmentId: string | null;
  deliveryDate: string | null;
  deliveryAddress: PostalAddress;
  customerReference: string | null;
  remarksPrinted: string | null;
  notesInternal: string | null;
  lines: DeliveryNoteLineInput[];
}

export interface EstablishmentOption {
  id: string;
  code: string;
  name: string;
  isDefault: boolean;
}

export interface CustomerOption {
  id: string;
  number: string;
  name: string;
  /** The tax families the customer's regime refuses on a line. */
  excludedFamilies: TaxFamily[];
}

export interface ProductOption {
  id: string;
  reference: string;
  name: string;
  unitId: string;
  unitPriceNet: string;
  defaultTaxComponentIds: string[];
}

export interface UnitOption {
  id: string;
  code: string;
  name: string;
  decimals: number;
}

export interface LineTaxOption {
  id: string;
  code: string;
  name: string;
  family: 'vat' | 'levy';
  rate: string;
  entersVatBase: boolean;
}

/** What the delivery note form offers: the company's currency and its active establishments, customers, products, units and line taxes. */
export interface DeliveryNoteOptions {
  currency: string;
  currencyScale: number;
  establishments: EstablishmentOption[];
  customers: CustomerOption[];
  products: ProductOption[];
  units: UnitOption[];
  taxes: LineTaxOption[];
}
