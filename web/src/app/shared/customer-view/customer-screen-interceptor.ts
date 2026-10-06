// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse, type HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { tap } from 'rxjs';
import { CustomerScreenHold } from './customer-screen-hold';

/** What the API answers to anything the customer screen does not reach while it holds the sign-in. */
const LOCKED = 'customer_screen_locked';

/**
 * A tab that was open before another one held the sign-in on the customer screen meets the API's refusal on its next
 * request: it reads the sign-in again and goes to the screen, as a new tab does through `customerScreenLock`. The
 * refusal still reaches whoever asked, who shows it as any other.
 */
export const customerScreenInterceptor: HttpInterceptorFn = (request, next) => {
  if (!request.url.startsWith('/api/')) return next(request);
  const hold = inject(CustomerScreenHold);
  const router = inject(Router);
  return next(request).pipe(
    tap({
      error: (error: unknown) => {
        if (!(error instanceof HttpErrorResponse) || error.status !== 403) return;
        const body: unknown = error.error;
        if (
          typeof body !== 'object' ||
          body === null ||
          !('error' in body) ||
          body.error !== LOCKED
        )
          return;
        void hold.reread().then(() => router.navigateByUrl('/customer-screen'));
      },
    }),
  );
};
