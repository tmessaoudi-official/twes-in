// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse, type HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { catchError, from, mergeMap, of, throwError } from 'rxjs';
import { Feedback } from '../feedback/feedback';

/** What the API answers to something a demo account may not do (DemoAccountGuard, and the sandbox's own refusals). */
export const DEMO_REFUSED = 'demo_refused';

/**
 * A demo visitor tries everything a company does, but nothing that would make the throwaway account a real one or reach
 * past the sandbox: whatever a screen still offers of that is refused by the API, and this says why in words, wherever
 * it was asked from. The refusal still reaches whoever asked, its body read when it came as a file's, so the screen can
 * tell it with `saidAlready` and say nothing more.
 */
export const demoRefusedInterceptor: HttpInterceptorFn = (request, next) => {
  if (!request.url.startsWith('/api/')) return next(request);
  const feedback = inject(Feedback);
  return next(request).pipe(
    catchError((error: unknown) => {
      // Only a file's refusal waits to be read: every other error reaches whoever asked at once, as it always has.
      const read = isFileRefusal(error) ? from(asRead(error)) : of(error);
      return read.pipe(
        mergeMap((answer) => {
          if (isDemoRefused(answer)) feedback.failure('auth.errors.demo_refused');
          return throwError(() => answer);
        }),
      );
    }),
  );
};

function isFileRefusal(error: unknown): error is HttpErrorResponse & { error: Blob } {
  return error instanceof HttpErrorResponse && error.status === 403 && error.error instanceof Blob;
}

/**
 * A file asked for (`responseType: 'blob'`) brings its refusal as a Blob. The demo's is handed on read; any other is
 * handed on as it came, since its reader expects the Blob, which reading here leaves whole.
 */
async function asRead(error: HttpErrorResponse & { error: Blob }): Promise<unknown> {
  let body: unknown;
  try {
    body = JSON.parse(await error.error.text());
  } catch {
    // A body that is not JSON is no refusal of the demo's.
    return error;
  }
  const read = new HttpErrorResponse({
    error: body,
    headers: error.headers,
    status: error.status,
    statusText: error.statusText,
    url: error.url ?? undefined,
  });
  return isDemoRefused(read) ? read : error;
}

/**
 * Whether an error is the demo's refusal, which the interceptor has already said in words: a screen leaves it there
 * rather than say a second, vaguer failure of its own (`saidAlready`, which reads it under a feature's refusal too).
 */
export function isDemoRefused(error: unknown): boolean {
  if (!(error instanceof HttpErrorResponse) || error.status !== 403) return false;
  const body: unknown = error.error;
  return (
    typeof body === 'object' && body !== null && 'error' in body && body.error === DEMO_REFUSED
  );
}
