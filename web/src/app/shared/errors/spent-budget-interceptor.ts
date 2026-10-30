// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse, HttpStatusCode, type HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { tap } from 'rxjs';
import { SpentBudget } from './spent-budget';

/** Hands every 429's `Retry-After` to `SpentBudget` before the screen reads the refusal, so it can say the hour. */
export const spentBudgetInterceptor: HttpInterceptorFn = (request, next) => {
  const budget = inject(SpentBudget);
  return next(request).pipe(
    tap({
      error: (error: unknown) => {
        if (error instanceof HttpErrorResponse && error.status === HttpStatusCode.TooManyRequests) {
          budget.heard(error.headers.get('Retry-After'));
        }
      },
    }),
  );
};
