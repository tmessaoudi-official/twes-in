// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse, type HttpInterceptorFn, HttpResponse } from '@angular/common/http';
import { inject } from '@angular/core';
import { tap } from 'rxjs';
import { Feedback } from '../feedback/feedback';
import {
  SUBMISSION_IN_PROGRESS,
  SUBMISSION_KEY_HEADER,
  SUBMISSION_UNANSWERED,
  SubmissionKeys,
  writeOf,
} from './submission-keys';

/**
 * Names every write this tab sends with a key (`SubmissionKeys`): the same write sent again before the API answered it
 * for good carries the same key, so a payment or a receipt sent twice is made once. The two answers about a key are
 * said here, wherever the write came from: one still being made, and one made whose answer was lost.
 */
export const submissionInterceptor: HttpInterceptorFn = (request, next) => {
  if (request.method !== 'POST' || !request.url.startsWith('/api/')) return next(request);
  const keys = inject(SubmissionKeys);
  const feedback = inject(Feedback);
  const write = writeOf(request.urlWithParams, request.body);
  return next(request.clone({ setHeaders: { [SUBMISSION_KEY_HEADER]: keys.keyFor(write) } })).pipe(
    tap({
      next: (event) => {
        if (event instanceof HttpResponse) keys.answered(write);
      },
      error: (error: unknown) => {
        const problem = submissionProblemOf(error);
        if (problem !== null) {
          feedback.notice(
            problem === SUBMISSION_IN_PROGRESS
              ? 'feedback.submission_in_progress'
              : 'feedback.submission_unanswered',
          );
        }
        if (!stillUnanswered(error)) keys.answered(write);
      },
    }),
  );
};

/** Whether the write may not have been made or answered yet: no answer at all, a server error, or one still running. */
function stillUnanswered(error: unknown): boolean {
  if (!(error instanceof HttpErrorResponse)) return true;
  return (
    error.status === 0 ||
    error.status >= 500 ||
    submissionProblemOf(error) === SUBMISSION_IN_PROGRESS
  );
}

/** Which of the two answers about a key an error is, or null for any other. */
export function submissionProblemOf(
  error: unknown,
): typeof SUBMISSION_IN_PROGRESS | typeof SUBMISSION_UNANSWERED | null {
  if (!(error instanceof HttpErrorResponse) || error.status !== 409) return null;
  const body: unknown = error.error;
  if (typeof body !== 'object' || body === null || !('type' in body)) return null;
  return body.type === SUBMISSION_IN_PROGRESS || body.type === SUBMISSION_UNANSWERED
    ? body.type
    : null;
}
