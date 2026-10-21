// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse } from '@angular/common/http';
import { DEMO_REFUSED } from '../demo/demo-refused-interceptor';
import { refusedFor } from '../errors/said-already';
import { SUBMISSION_IN_PROGRESS, SUBMISSION_UNANSWERED } from '../submissions/submission-keys';

/** The API's answer to something a demo account may not do, which the demo interceptor says in words. */
export function demoAnswer(): HttpErrorResponse {
  return new HttpErrorResponse({ status: 403, error: { error: DEMO_REFUSED } });
}

/** The API's answer about a write's key, which the submission interceptor says in words. */
export function submissionAnswer(
  type: typeof SUBMISSION_IN_PROGRESS | typeof SUBMISSION_UNANSWERED,
): HttpErrorResponse {
  return new HttpErrorResponse({ status: 409, error: { type, status: 409 } });
}

/** A feature's refusal as its adapter builds it from the demo's answer: its own code, the answer kept as its cause. */
export function refusedByTheDemo<R extends Error>(refusal: R): R {
  return refusedFor(demoAnswer(), refusal);
}
