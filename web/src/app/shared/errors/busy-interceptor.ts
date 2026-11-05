// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse, type HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { catchError, throwError, timer, switchMap } from 'rxjs';
import { Feedback } from '../feedback/feedback';

/** The API's answer when another write met this one in the database and this one gave way, nothing kept. */
export const BUSY = '/problems/busy';

/** How long to wait before the one try again when the answer names no `Retry-After`. */
const DEFAULT_WAIT_S = 1;

/**
 * Another write met this one in the database (the same number taken at once, a deadlock): the API kept nothing and
 * asks for the request again. It is sent once more after its `Retry-After`, unseen, which nearly always goes through or
 * meets what the other write left (the number now taken, said by the screen). Busy a second time, it is said here in
 * words, kept until closed, and `saidAlready` tells the screen not to say it again. Placed after the CSRF interceptor, so the second try
 * carries what the first did, and before the submission keys, so it carries a key of its own: nothing was made.
 */
export const busyInterceptor: HttpInterceptorFn = (request, next) => {
  const feedback = inject(Feedback);
  return next(request).pipe(
    catchError((first: unknown) => {
      if (!isBusy(first)) return throwError(() => first);
      return timer(waitOf(first) * 1_000).pipe(
        switchMap(() => next(request)),
        catchError((second: unknown) => {
          if (isBusy(second)) feedback.failure('feedback.busy');
          return throwError(() => second);
        }),
      );
    }),
  );
};

/** Whether an error is the API's busy answer. */
export function isBusy(error: unknown): boolean {
  if (!(error instanceof HttpErrorResponse) || error.status !== 409) return false;
  const body: unknown = error.error;
  return typeof body === 'object' && body !== null && 'type' in body && body.type === BUSY;
}

function waitOf(error: unknown): number {
  const header = error instanceof HttpErrorResponse ? error.headers.get('Retry-After') : null;
  const seconds = header === null ? NaN : Number(header);
  return Number.isFinite(seconds) && seconds >= 0 && seconds <= 10 ? seconds : DEFAULT_WAIT_S;
}
