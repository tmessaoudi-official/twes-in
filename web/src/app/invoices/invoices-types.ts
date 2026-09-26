// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ProductTracking } from '../products/products-types';
import type { StatusTone } from '../shared/theme/accent-theme';
import { type LifecycleStage, tonesOf } from '../shared/theme/lifecycle-tones';

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
 * reads as overdue (docs/SPEC.md § 7, 2026-09-16). Overdue is still worked out on screen for the row that is shown,
 * and the API answers it as a status to narrow by, against the company's own day, so that a filtered list is a page
 * of what matches rather than a page filtered after the fact.
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

/**
 * Where each status stands (design direction § 1.1): a draft not started, an issued document under way, one partly paid
 * waiting on the person, a paid one done, a cancelled one withdrawn, one overdue alarming. The tones derive from it.
 */
export const INVOICE_STATUS_STAGES: Readonly<Record<InvoiceShownStatus, LifecycleStage>> = {
  draft: 'not-started',
  issued: 'under-way',
  partially_paid: 'needs-action',
  paid: 'done',
  cancelled: 'withdrawn',
  overdue: 'alarm',
};

export const INVOICE_STATUS_TONES: Readonly<Record<InvoiceShownStatus, StatusTone>> =
  tonesOf(INVOICE_STATUS_STAGES);

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
  /**
   * The product's reference and name as they read today, which is what lets the line be shown without the catalogue.
   * Both null for a line naming no product, or one whose product has since been deleted. Read only: the API fills
   * them from the product the line names and ignores them on the way back.
   */
  productReference: string | null;
  productName: string | null;
  /** How the product's stock is told apart today; null for a line naming no product. Read only. */
  productTracking: ProductTracking | null;
  /** The lot or serial sold, for a product tracked by one (docs/SPEC.md § 7, 2026-09-24 12:40 row 5). */
  lotCode: string | null;
  /** The line after its own discount, at the currency's scale, worked out by the API. */
  net: string;
}

export type InvoiceLineInput = Omit<
  InvoiceLine,
  'net' | 'productReference' | 'productName' | 'productTracking'
>;

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

/** What the API sorts invoices by. */
export type InvoiceSortKey = 'number' | 'customer' | 'issueDate' | 'dueDate' | 'status';

/** One page of the invoices list as the API searches, narrows and sorts it (docs/SPEC.md § 7, lists at scale). */
/**
 * How many documents each status chip would list, under the list's own words, kind and customer (docs/SPEC.md § 7,
 * 2026-09-26). An overdue document is counted in its status too, as the list shows it under both.
 */
export interface InvoiceStatusCounts {
  all: number;
  statuses: Record<InvoiceShownStatus, number>;
}

export interface InvoiceSearch {
  /** Numbered from 1. */
  page: number;
  itemsPerPage: number;
  /** Words found in the number, the customer's reference, or the customer the document recorded; empty finds all. */
  q: string;
  /** A status the document holds, or `overdue`, which the API answers against the company's own day. */
  status: InvoiceShownStatus | null;
  documentType: InvoiceType | null;
  customerId: string | null;
  order: { key: InvoiceSortKey; direction: 'asc' | 'desc' } | null;
}

export interface InvoiceRow {
  id: string;
  type: InvoiceType;
  /** The invoice a credit note corrects; null for an invoice. */
  correctsInvoiceId: string | null;
  /** Why a credit note corrects its invoice, stated when it was drafted; null for an invoice. */
  creditNoteReason: string | null;
  /** Null until the document is issued. */
  number: string | null;
  status: InvoiceStatus;
  customerId: string;
  /** The customer's name as the document recorded it when issued; null for a draft, which has recorded nothing. */
  recordedCustomerName: string | null;
  /** The customer as it reads TODAY, so a form shows who the document is for without the company's whole book. */
  customerName: string;
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

/** One customer as a picker answers it: what a document header starts from. */
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

/** One product as a picker answers it: what a line starts from. */
export interface ProductOption {
  id: string;
  reference: string;
  name: string;
  unitId: string;
  unitPriceNet: string;
  defaultTaxComponentIds: string[];
  /** Whether a line of it names the lot or serial sold. */
  tracking: ProductTracking;
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

/**
 * What the invoice form offers: the company's currency, its establishments, its units and its taxes — what is small,
 * bounded and needed all at once. The customers and the products are NOT here: they are asked for a few at a time
 * through the two pickers (docs/SPEC.md § 7, 2026-09-17, ruling 3).
 */
export interface InvoiceOptions {
  currency: string;
  currencyScale: number;
  establishments: EstablishmentOption[];
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
