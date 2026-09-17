// SPDX-License-Identifier: AGPL-3.0-or-later

/** How a company says it paid. */
export type PaymentMethod = 'cash' | 'transfer' | 'cheque' | 'other';

export const PAYMENT_METHODS: readonly PaymentMethod[] = ['cash', 'transfer', 'cheque', 'other'];

/** A payment a company declared, and where the operator's decision left it. */
export interface PaymentRow {
  readonly id: string;
  readonly amount: string;
  readonly currency: string;
  readonly method: PaymentMethod;
  /** The day the company says it paid, YYYY-MM-DD in its own timezone. */
  readonly paidOn: string;
  readonly reference: string | null;
  readonly note: string | null;
  readonly status: 'declared' | 'confirmed' | 'rejected';
  readonly declaredAt: string;
  readonly decidedAt: string | null;
  readonly decisionNote: string | null;
}

/** A payment waiting for a decision, as the operator's queue lists it: with the company that declared it. */
export interface WaitingPayment extends PaymentRow {
  readonly companyId: string;
  readonly companyName: string;
}

/** What a company declares: what it paid, how and when. */
export interface DeclaredPayment {
  readonly amount: string;
  readonly currency: string;
  readonly method: PaymentMethod;
  readonly paidOn: string;
  readonly reference: string | null;
  readonly note: string | null;
}

/**
 * A company's own view of its subscription: where it stands, what it costs, and what it declared. `canDeclare` is
 * what the page offers; the API enforces it either way.
 */
export interface SubscriptionView {
  readonly companyId: string;
  readonly stage: 'trial' | 'paid' | 'grace' | 'held' | 'unpaid';
  readonly access: 'full' | 'read_only' | 'locked';
  readonly coveredUntil: string;
  readonly graceEndsAt: string;
  readonly daysLeft: number | null;
  readonly trialEndsOn: string | null;
  readonly paidThrough: string | null;
  readonly periodCount: number;
  readonly periodUnit: 'day' | 'month' | 'year';
  readonly price: string | null;
  readonly currency: string | null;
  readonly openPayment: PaymentRow | null;
  readonly payments: readonly PaymentRow[];
  readonly canDeclare: boolean;
}

/**
 * Licensing does not manage this company (the API answers 404 and it owes nothing), a payment already waits for a
 * decision, the payment was refused as written, refused otherwise, or the API could not be reached.
 */
export type SubscriptionError =
  'not_managed' | 'already_declared' | 'invalid' | 'refused' | 'network';
