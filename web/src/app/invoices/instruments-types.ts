// SPDX-License-Identifier: AGPL-3.0-or-later

import { type LifecycleStage, tonesOf } from '../shared/theme/lifecycle-tones';

/** What a customer handed over in place of money: a cheque, or a bill of exchange (traite). */
export type InstrumentKind = 'check' | 'draft';
export const INSTRUMENT_KINDS: readonly InstrumentKind[] = ['check', 'draft'];

/**
 * Where an instrument stands (docs/SPEC.md § 7, 2026-09-21 18:40): held in the portfolio, handed to the bank, then
 * cashed (and only then a payment) or unpaid.
 */
export type InstrumentStatus = 'held' | 'deposited' | 'cashed' | 'unpaid';
export const INSTRUMENT_STATUSES: readonly InstrumentStatus[] = [
  'held',
  'deposited',
  'cashed',
  'unpaid',
];

export const INSTRUMENT_STATUS_STAGES: Readonly<Record<InstrumentStatus, LifecycleStage>> = {
  held: 'not-started',
  deposited: 'under-way',
  cashed: 'done',
  unpaid: 'alarm',
};
export const INSTRUMENT_STATUS_TONES = tonesOf(INSTRUMENT_STATUS_STAGES);

/** The steps an instrument takes, each one a request of its own. */
export type InstrumentStep = 'deposit' | 'cash' | 'unpaid';

export interface InstrumentRow {
  id: string;
  kind: InstrumentKind;
  /** The API's decimal string. */
  amount: string;
  /** The day it falls due (ISO). */
  dueOn: string;
  bank: string | null;
  number: string | null;
  status: InstrumentStatus;
  /** The day it was cashed or came back unpaid; null while it is open. */
  settledOn: string | null;
  /** The payment cashing it recorded. */
  paymentId: string | null;
}

export interface InstrumentInput {
  kind: InstrumentKind;
  amount: string;
  dueOn: string;
  bank: string | null;
  number: string | null;
}
