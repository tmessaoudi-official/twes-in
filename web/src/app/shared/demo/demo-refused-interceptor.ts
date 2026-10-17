// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse, type HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { tap } from 'rxjs';
import { Feedback } from '../feedback/feedback';

/** What the API answers to something a demo account may not do (DemoAccountGuard, and the sandbox's own refusals). */
export const DEMO_REFUSED = 'demo_refused';

/**
 * A demo visitor tries everything a company does, but nothing that would make the throwaway account a real one or reach
 * past the sandbox: whatever a screen still offers of that is refused by the API, and this says why in words, wherever
 * it was asked from. The refusal still reaches whoever asked, who shows it as any other.
 */
export const demoRefusedInterceptor: HttpInterceptorFn = (request, next) => {
  if (!request.url.startsWith('/api/')) return next(request);
  const feedback = inject(Feedback);
  return next(request).pipe(
    tap({
      error: (error: unknown) => {
        if (!(error instanceof HttpErrorResponse) || error.status !== 403) return;
        const body: unknown = error.error;
        if (
          typeof body === 'object' &&
          body !== null &&
          'error' in body &&
          body.error === DEMO_REFUSED
        ) {
          feedback.failure('auth.errors.demo_refused');
        }
      },
    }),
  );
};
