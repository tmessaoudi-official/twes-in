// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, Injectable, signal } from '@angular/core';

/** What the operator sets for the page itself, as `/api/health` says it (`app.client_errors.per_page`, …). */
export interface WebParameterValues {
  /** The errors one page load reports; 0 sends none. */
  errorReportsPerPage: number;
  /** The minutes a write whose answer never came keeps its key for being sent again. */
  submissionRetryMinutes: number;
  /** How long a password reset link works, in seconds (`app.password_reset.valid_for`). */
  passwordResetSeconds: number;
  /** How long a signup link works, in seconds (`app.signup.valid_for`). */
  signupSeconds: number;
  /** How long a confirmed step-up proof holds, in seconds (`app.step_up.valid_for`). */
  stepUpSeconds: number;
  /** How long a delete, a merge or an import can be taken back, in seconds (`app.records.undoable_for`). */
  recordsUndoableSeconds: number;
  /** How long an erasure can be taken back, in seconds (`app.erasure.undoable_for`). */
  erasureUndoableSeconds: number;
}

/**
 * The shipped values, which the page holds to until the API has said the operator's: the same numbers services.yaml
 * ships, so a page that never hears the API behaves as every page did before they were parameters.
 */
export const WEB_PARAMETERS_UNTIL_TOLD: WebParameterValues = {
  errorReportsPerPage: 5,
  submissionRetryMinutes: 15,
  passwordResetSeconds: 3600,
  signupSeconds: 86400,
  stepUpSeconds: 300,
  recordsUndoableSeconds: 86400,
  erasureUndoableSeconds: 86400,
};

/**
 * The operator's values for the page, told by `/api/health`, which every page reads as it starts (`BuildInfo`), signed
 * out too. Holds no request of its own, so an interceptor may read it without building the HTTP client again.
 */
@Injectable({ providedIn: 'root' })
export class WebParameters {
  private readonly told = signal<WebParameterValues | null>(null);
  private readonly values = computed(() => this.told() ?? WEB_PARAMETERS_UNTIL_TOLD);

  /** How many errors this page load reports; 0 sends none. */
  readonly errorReportsPerPage = computed(
    () => (this.told() ?? WEB_PARAMETERS_UNTIL_TOLD).errorReportsPerPage,
  );
  /** How long a write whose answer never came keeps its key for being sent again, in milliseconds. */
  readonly submissionRetryMs = computed(
    () => (this.told() ?? WEB_PARAMETERS_UNTIL_TOLD).submissionRetryMinutes * 60 * 1000,
  );

  /**
   * The durations a screen states, in seconds, which it says through the `duration` pipe: a text never names a fixed
   * hour the operator may have changed.
   */
  readonly passwordResetSeconds = computed(() => this.values().passwordResetSeconds);
  readonly signupSeconds = computed(() => this.values().signupSeconds);
  readonly stepUpSeconds = computed(() => this.values().stepUpSeconds);
  readonly recordsUndoableSeconds = computed(() => this.values().recordsUndoableSeconds);
  readonly erasureUndoableSeconds = computed(() => this.values().erasureUndoableSeconds);

  /** What the API said; the last answer wins, so an operator's change reaches a page left open. */
  tell(values: WebParameterValues): void {
    this.told.set(values);
  }
}
