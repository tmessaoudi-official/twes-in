// SPDX-License-Identifier: AGPL-3.0-or-later

import type { Schedule } from '../shared/schedule/schedule';

/** How often a recurring invoice is drafted again, as the API names it. */
/** A recurring invoice as the screens show it: what it copies, when, and where it stands. */
export interface RecurringInvoiceRow {
  readonly id: string;
  readonly modelInvoiceId: string;
  /** The model's number; null while the model is a draft. */
  readonly modelNumber: string | null;
  readonly customerName: string;
  readonly schedule: Schedule;
  readonly startsOn: string;
  /** The last day a draft may be made on; null when it goes on. */
  readonly endsOn: string | null;
  readonly paused: boolean;
  /** The day of the next draft; null once past the last day. */
  readonly nextOn: string | null;
  readonly drafted: number;
  readonly lastInvoiceId: string | null;
  /** The version read, which a revision or a pause sends back: a draft made since moves it too. */
  readonly version: number;
}

/** What making an invoice recurring asks. */
export interface RecurringInvoiceDraft {
  readonly modelInvoiceId: string;
  readonly schedule: Schedule;
  readonly startsOn: string;
  readonly endsOn: string | null;
}

/** What a recurring invoice's revision changes. */
export interface RecurringInvoiceRevision {
  readonly schedule: Schedule;
  readonly endsOn: string | null;
  readonly paused: boolean;
  /** The version the row was read at; one saved since is refused as `record_changed`. */
  readonly version: number;
}

/** Why the API refused, which the screens translate; `field` names what a 422 was about. */
export type RecurringError = 'invalid' | 'not_found' | 'record_changed' | 'network';

/** Where it stands: drafting, paused, or over once past its last day. */
export function recurringState(row: RecurringInvoiceRow): 'active' | 'paused' | 'ended' {
  if (row.nextOn === null) {
    return 'ended';
  }
  return row.paused ? 'paused' : 'active';
}
