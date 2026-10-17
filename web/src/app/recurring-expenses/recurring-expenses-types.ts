// SPDX-License-Identifier: AGPL-3.0-or-later

import type { Schedule } from '../shared/schedule/schedule';

/** A recurring expense as the screens show it: what it copies, when, and where it stands. */
export interface RecurringExpenseRow {
  readonly id: string;
  readonly modelExpenseId: string;
  /** The model's description; empty once the model is gone. */
  readonly description: string;
  /** The model's vendor, or who it was paid to in words. */
  readonly payee: string | null;
  /** What the model comes to, the figure each draft starts from. */
  readonly amountGross: string | null;
  readonly currency: string | null;
  readonly schedule: Schedule;
  readonly startsOn: string;
  /** The last day an occurrence may fall on; null when it goes on. */
  readonly endsOn: string | null;
  /** Each bill comes to a different amount: every draft is flagged « montant à vérifier ». */
  readonly variableAmount: boolean;
  readonly paused: boolean;
  /** The day of the next draft; null once past the last day. */
  readonly nextOn: string | null;
  readonly drafted: number;
  readonly lastExpenseId: string | null;
  /** The version read, which a revision or a pause sends back: a draft made since moves it too. */
  readonly version: number;
}

/** What making an expense recurring asks. */
export interface RecurringExpenseDraft {
  readonly modelExpenseId: string;
  readonly schedule: Schedule;
  readonly startsOn: string;
  readonly endsOn: string | null;
  readonly variableAmount: boolean;
}

/** What a recurring expense's revision changes: everything but its model and its first day. */
export interface RecurringExpenseRevision {
  readonly schedule: Schedule;
  readonly endsOn: string | null;
  readonly variableAmount: boolean;
  readonly paused: boolean;
  /** The version the row was read at; one saved since is refused as `record_changed`. */
  readonly version: number;
}

/** Why the API refused, which the screens translate. */
export type RecurringExpenseError = 'invalid' | 'not_found' | 'record_changed' | 'network';

/** Where it stands: drafting, paused, or over once past its last day. */
export function recurringExpenseState(row: RecurringExpenseRow): 'active' | 'paused' | 'ended' {
  if (row.nextOn === null) {
    return 'ended';
  }
  return row.paused ? 'paused' : 'active';
}
