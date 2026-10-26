// SPDX-License-Identifier: AGPL-3.0-or-later

import type { StatusTone } from '../shared/theme/accent-theme';
import { type LifecycleStage, tonesOf } from '../shared/theme/lifecycle-tones';
import type { InvoiceLine, TaxTotal } from '../invoices/invoices-types';
import type { Receiver, ReceiverChoice, ReceiverRule } from '../shared/receivers/receiver';

/** Why the API refused, as the quotes screens translate it. */
export type QuotesError =
  | 'network'
  | 'not_found'
  | 'conflict'
  /** Saved over a version someone else saved since: nothing was saved, and what they saved is to be merged. */
  | 'record_changed'
  | 'invalid'
  | 'customer_unavailable'
  | 'file_refused'
  | 'files_full'
  | 'deposit_refused'
  | 'deposit_pending'
  /** « Livrer » refused a quantity: more than is left of its line, or nothing left at all. */
  | 'delivery_refused'
  /** « Livrer » refused a quote invoiced as a whole: a discount on its total, or a deposit. */
  | 'delivery_whole';

export type QuoteStatus = 'draft' | 'sent' | 'accepted' | 'refused' | 'cancelled';
export const QUOTE_STATUSES: readonly QuoteStatus[] = [
  'draft',
  'sent',
  'accepted',
  'refused',
  'cancelled',
];

/** What a list or a header shows: a sent quote past its validity day reads « Expiré », which the API never stores. */
export type QuoteShownStatus = QuoteStatus | 'expired';

/**
 * Where each status stands: a draft is not started, a sent quote waits on the customer, an expired one asks the
 * company to chase or let go, an accepted one is done, and a refused or cancelled one is out of play.
 */
export const QUOTE_STATUS_STAGES: Readonly<Record<QuoteShownStatus, LifecycleStage>> = {
  draft: 'not-started',
  sent: 'under-way',
  expired: 'needs-action',
  accepted: 'done',
  refused: 'withdrawn',
  cancelled: 'withdrawn',
};

export const QUOTE_STATUS_TONES: Readonly<Record<QuoteShownStatus, StatusTone>> =
  tonesOf(QUOTE_STATUS_STAGES);

/** The sorts the API answers; the total is worked out per row and is not a column it can order by. */
export type QuoteSortKey = 'number' | 'customer' | 'issueDate' | 'validUntil' | 'status';

export interface QuoteStatusCounts {
  all: number;
  statuses: Record<QuoteStatus, number>;
}

export interface QuoteSearch {
  /** Numbered from 1. */
  page: number;
  itemsPerPage: number;
  /** Words found in the number, the customer's reference, the customer the quote recorded or who answered; empty finds all. */
  q: string;
  /** Statuses the quote may hold, OR'd; none lists every one. */
  status: readonly QuoteStatus[];
  customerIds: readonly string[];
  /** The ends of the issue day's interval, by `issueDate.from` and `issueDate.to`: each already valid. */
  intervals: Readonly<Record<string, string>>;
  order: { key: QuoteSortKey; direction: 'asc' | 'desc' } | null;
}

/**
 * A quote line in the shape the invoice's lines editor reads, which a quote's screen reuses: a quote names no delivery
 * note, no lot and gives nothing back, so those are always empty.
 */
export type QuoteLine = InvoiceLine;

export interface QuoteRow {
  id: string;
  /** The version read, which a revision sends back; one saved since is refused as `record_changed`. */
  version: number;
  /** Null until the quote is sent. */
  number: string | null;
  status: QuoteStatus;
  /** A sent quote past its validity day, worked out by the API on the company's day. */
  expired: boolean;
  customerId: string;
  /** The customer's name as the quote recorded it when sent; null for a draft. */
  recordedCustomerName: string | null;
  /** The customer as it reads today. */
  customerName: string;
  establishmentId: string | null;
  issueDate: string | null;
  validUntil: string | null;
  /** The day the customer accepted or refused it. */
  answeredOn: string | null;
  refusalReason: string | null;
  /** Who answered for the customer, as the quote copied them; null while nobody is named. */
  receivedBy: Receiver | null;
  /**
   * Whether the customer's quotes name who answered: never, when wished, or always at the answer. Said by a quote's own
   * answer; null on a list's rows, which read no setting: a screen reads it `?? 'optional'`.
   */
  receiverRule: ReceiverRule | null;
  /** The draft invoice an accepted quote became. */
  invoiceId: string | null;
  /** The deposit invoices drawn from it, cancelled drafts included, the oldest first. */
  deposits: QuoteDeposit[];
  /** The delivery notes that are not cancelled delivering it, drafts included, the oldest first; none unseen. */
  deliveryNotes: QuoteDeliveryNote[];
  /** What its notes past their draft delivered: none, part, or every line whole. */
  delivery: QuoteDelivery;
  /** Each line's quantity, what notes take of it (drafts included) and what they delivered, by line, in order. */
  deliveries: QuoteLineDelivery[];
  attachmentCount: number;
  customerReference: string | null;
  notesPrinted: string | null;
  notesInternal: string | null;
  discountAmount: string | null;
  lines: QuoteLine[];
  subtotalNet: string;
  documentDiscount: string;
  /** What the line discounts and the document discount take off together. */
  savings: string;
  taxes: TaxTotal[];
  totalTax: string;
  total: string;
}

export interface QuoteLineInput {
  productId: string | null;
  description: string;
  quantity: string;
  unitId: string;
  unitPriceNet: string;
  discountRate: string | null;
  /** The discount as an amount taken off the whole line, in place of a rate. Never both. */
  discountAmount: string | null;
  taxComponentIds: string[];
}

export interface QuoteInput {
  customerId: string;
  establishmentId: string | null;
  customerReference: string | null;
  notesPrinted: string | null;
  notesInternal: string | null;
  discountAmount: string | null;
  lines: QuoteLineInput[];
}

/** A deposit invoice (facture d'acompte) drawn from a quote, as the quote lists it. */
export interface QuoteDeposit {
  invoiceId: string;
  /** Null while it is a draft. */
  number: string | null;
  status: 'draft' | 'issued' | 'partially_paid' | 'paid' | 'cancelled';
  /** Tax and fixed charges included, at the currency's scale. */
  total: string;
}

export type QuoteDelivery = 'none' | 'partly' | 'whole';

/** A quote its notes delivered part of is under way; one delivered whole is done. Undelivered, nothing is shown. */
export const QUOTE_DELIVERY_STAGES: Readonly<
  Record<Exclude<QuoteDelivery, 'none'>, LifecycleStage>
> = {
  partly: 'under-way',
  whole: 'done',
};

export const QUOTE_DELIVERY_TONES = tonesOf(QUOTE_DELIVERY_STAGES);

/** A delivery note delivering an accepted quote, as the quote lists it. */
export interface QuoteDeliveryNote {
  deliveryNoteId: string;
  /** Null while it is a draft. */
  number: string | null;
  status: 'draft' | 'validated' | 'delivered' | 'invoiced';
}

/** One quote line as its deliveries read: `left` is its quantity less what notes take, drafts included. */
export interface QuoteLineDelivery {
  lineId: string;
  description: string;
  quantity: string;
  onNotes: string;
  delivered: string;
  left: string;
}

/** A deposit asked for: a percentage of the quote, or an amount tax included; one of them. */
export type DepositShare = { percentage: string } | { amount: string };

/**
 * The customer's answer: the day they gave it (empty: the company's today), why they declined, when told, and who
 * answered for them, as chosen (none: nobody).
 */
export interface QuoteAnswer {
  answeredOn: string;
  refusalReason?: string;
  receivedBy?: ReceiverChoice | null;
}

export interface QuoteAttachment {
  id: string;
  name: string;
  mime: string;
  size: number;
  createdAt: string;
}
