// SPDX-License-Identifier: AGPL-3.0-or-later

import type { StatusTone } from '../shared/theme/accent-theme';

/** Why the API refused, as the invoices screens translate it. */
export type InvoicesError = 'network' | 'not_found' | 'conflict' | 'invalid';

export type InvoiceType = 'invoice' | 'credit_note';

export type InvoiceStatus = 'draft' | 'issued' | 'partially_paid' | 'paid' | 'cancelled';
export const INVOICE_STATUSES: readonly InvoiceStatus[] = [
  'draft',
  'issued',
  'partially_paid',
  'paid',
  'cancelled',
];

/**
 * What a list shows for a document: its status, except that an issued or partly paid invoice whose due day has passed
 * reads as overdue (docs/SPEC.md § 7, 2026-09-16). Overdue is worked out on screen; the API knows no such status.
 */
export type InvoiceShownStatus = InvoiceStatus | 'overdue';
export const INVOICE_SHOWN_STATUSES: readonly InvoiceShownStatus[] = [
  'draft',
  'issued',
  'overdue',
  'partially_paid',
  'paid',
  'cancelled',
];

/** A status's tone: a draft is quiet, an issued document under way, one overdue alarming, a paid one done. */
export const INVOICE_STATUS_TONES: Readonly<Record<InvoiceShownStatus, StatusTone>> = {
  draft: 'neutral',
  issued: 'info',
  overdue: 'danger',
  partially_paid: 'warning',
  paid: 'success',
  cancelled: 'neutral',
};

export type TaxFamily = 'vat' | 'levy' | 'stamp' | 'withholding';

export interface InvoiceLine {
  productId: string | null;
  description: string;
  /** A decimal string, "2" or "1.5", never finer than its unit counts. */
  quantity: string;
  unitId: string;
  /** A decimal string with up to four decimals. */
  unitPriceNet: string;
  /** A percentage with up to three decimals; null for none. */
  discountRate: string | null;
  taxComponentIds: string[];
  /** The delivery note line this line invoices; a revision may keep or drop it, never add one. */
  sourceDeliveryNoteLineId: string | null;
  /** The line after its own discount, at the currency's scale, worked out by the API. */
  net: string;
}

export type InvoiceLineInput = Omit<InvoiceLine, 'net'>;

export interface TaxTotal {
  code: string;
  rate: string;
  base: string;
  amount: string;
}

export interface FixedTax {
  code: string;
  amount: string;
}

export type PaymentMethod = 'transfer' | 'cash' | 'check' | 'card' | 'other';
export const PAYMENT_METHODS: readonly PaymentMethod[] = [
  'transfer',
  'cash',
  'check',
  'card',
  'other',
];

export interface Payment {
  id: string;
  date: string;
  amount: string;
  method: PaymentMethod;
  reference: string | null;
  notes: string | null;
}

export interface PaymentInput {
  date: string;
  amount: string;
  method: PaymentMethod;
  reference: string | null;
  notes: string | null;
}

export interface InvoiceRow {
  id: string;
  type: InvoiceType;
  /** The invoice a credit note corrects; null for an invoice. */
  correctsInvoiceId: string | null;
  /** Null until the document is issued. */
  number: string | null;
  status: InvoiceStatus;
  customerId: string;
  /** The customer's name as the document was issued to it; null for a draft, which names today's customer. */
  customerName: string | null;
  establishmentId: string | null;
  issueDate: string | null;
  dueDate: string | null;
  supplyDate: string | null;
  paymentTermsDays: number | null;
  customerReference: string | null;
  notesPrinted: string | null;
  notesInternal: string | null;
  /** The document discount as it was typed; null for none. */
  discountAmount: string | null;
  /** The fixed charges and withholdings chosen; null takes the company's and the customer's defaults. */
  documentTaxComponentIds: string[] | null;
  lines: InvoiceLine[];
  subtotalNet: string;
  documentDiscount: string;
  totalNet: string;
  taxes: TaxTotal[];
  totalTax: string;
  fixedTaxes: FixedTax[];
  total: string;
  withholdings: TaxTotal[];
  amountDue: string;
  amountPaid: string;
  amountCredited: string;
  payments: Payment[];
}

export interface InvoiceInput {
  customerId: string;
  establishmentId: string | null;
  supplyDate: string | null;
  paymentTermsDays: number | null;
  customerReference: string | null;
  notesPrinted: string | null;
  notesInternal: string | null;
  discountAmount: string | null;
  documentTaxComponentIds: string[] | null;
  lines: InvoiceLineInput[];
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
  /** The tax families the customer's regime refuses. */
  excludedFamilies: TaxFamily[];
  /** What a new line of this customer's is discounted by; null for nothing. */
  defaultDiscountRate: string | null;
  /** The fixed charges and withholdings the customer is charged besides the company's defaults. */
  defaultTaxComponentIds: string[];
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

export type TaxKind = 'percentage_line' | 'fixed_document' | 'withholding_total';

export interface TaxOption {
  id: string;
  code: string;
  name: string;
  kind: TaxKind;
  family: TaxFamily;
  rate: string | null;
  amount: string | null;
  threshold: string | null;
  isDefault: boolean;
}

/** What the invoice form offers: the company's currency and its establishments, customers, products, units and taxes. */
export interface InvoiceOptions {
  currency: string;
  currencyScale: number;
  establishments: EstablishmentOption[];
  customers: CustomerOption[];
  products: ProductOption[];
  units: UnitOption[];
  taxes: TaxOption[];
}

/** Where an amount still due stands against its due day: not yet due, then by how many days late. */
export type AgingBucket = 'not_due' | 'days_1_15' | 'days_16_30' | 'days_31_45' | 'days_over_45';
export const AGING_BUCKETS: readonly AgingBucket[] = [
  'not_due',
  'days_1_15',
  'days_16_30',
  'days_31_45',
  'days_over_45',
];

export interface AgingAmount {
  readonly bucket: AgingBucket;
  readonly amount: string;
  readonly count: number;
}

/** An invoice to chase: `daysLate` is negative while its due day is still ahead. */
export interface InvoiceToChase {
  readonly invoiceId: string;
  readonly number: string;
  readonly customerName: string;
  readonly dueDate: string;
  readonly amountDue: string;
  readonly daysLate: number;
}

export interface MonthCollected {
  /** YYYY-MM */
  readonly month: string;
  readonly amount: string;
}

export interface VatCollected {
  readonly code: string;
  readonly rate: string;
  readonly amount: string;
}

/** The home page's figures of the company's invoices, every one worked out by the API on the company's day. */
export interface InvoiceSummary {
  readonly currency: string;
  readonly currencyScale: number;
  /** The company's day, YYYY-MM-DD. */
  readonly today: string;
  readonly outstanding: string;
  readonly notYetDue: string;
  readonly overdue: string;
  readonly overdueCount: number;
  readonly oldestOverdueDays: number | null;
  readonly aging: readonly AgingAmount[];
  /** The first four; `toChaseCount` and `toChaseAmount` cover them all. */
  readonly toChase: readonly InvoiceToChase[];
  readonly toChaseCount: number;
  readonly toChaseAmount: string;
  /** The last six months, the oldest first. */
  readonly collected: readonly MonthCollected[];
  readonly vat: readonly VatCollected[];
  readonly vatTotal: string;
}
