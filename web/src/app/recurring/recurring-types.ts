// SPDX-License-Identifier: AGPL-3.0-or-later

/** How often a recurring invoice is drafted again, as the API names it. */
export const RECURRING_FREQUENCIES = ['weekly', 'monthly', 'quarterly', 'yearly'] as const;

export type RecurringFrequency = (typeof RECURRING_FREQUENCIES)[number];

/** A recurring invoice as the screens show it: what it copies, how often, and where it stands. */
export interface RecurringInvoiceRow {
  readonly id: string;
  readonly modelInvoiceId: string;
  /** The model's number; null while the model is a draft. */
  readonly modelNumber: string | null;
  readonly customerName: string;
  readonly frequency: RecurringFrequency;
  readonly startsOn: string;
  /** The last day a draft may be made on; null when it goes on. */
  readonly endsOn: string | null;
  readonly paused: boolean;
  /** The day of the next draft; null once past the last day. */
  readonly nextOn: string | null;
  readonly drafted: number;
  readonly lastInvoiceId: string | null;
}

/** What making an invoice recurring asks. */
export interface RecurringInvoiceDraft {
  readonly modelInvoiceId: string;
  readonly frequency: RecurringFrequency;
  readonly startsOn: string;
  readonly endsOn: string | null;
}

/** What a recurring invoice's revision changes. */
export interface RecurringInvoiceRevision {
  readonly frequency: RecurringFrequency;
  readonly endsOn: string | null;
  readonly paused: boolean;
}

/** Why the API refused, which the screens translate; `field` names what a 422 was about. */
export type RecurringError = 'invalid' | 'not_found' | 'network';

/** Where it stands: drafting, paused, or over once past its last day. */
export function recurringState(row: RecurringInvoiceRow): 'active' | 'paused' | 'ended' {
  if (row.nextOn === null) {
    return 'ended';
  }
  return row.paused ? 'paused' : 'active';
}
