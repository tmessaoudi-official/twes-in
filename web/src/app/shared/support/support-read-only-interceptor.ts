// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse, type HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { tap } from 'rxjs';
import { Feedback } from '../feedback/feedback';

/** What the API answers to a write or an export asked under the owner's « Accès du support ». */
export const SUPPORT_READ_ONLY = 'support_read_only';

/**
 * Under « Accès du support » the platform's support only reads: whatever a screen still offers that writes or exports
 * is refused by the API, and this says why in words, wherever it was asked from. The refusal still reaches whoever
 * asked, who shows it as any other.
 */
export const supportReadOnlyInterceptor: HttpInterceptorFn = (request, next) => {
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
          body.error === SUPPORT_READ_ONLY
        ) {
          feedback.failure('support_access.read_only');
        }
      },
    }),
  );
};
