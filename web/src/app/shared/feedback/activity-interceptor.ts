// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  HttpContextToken,
  HttpErrorResponse,
  type HttpInterceptorFn,
  HttpResponse,
} from '@angular/common/http';
import { inject } from '@angular/core';
import { finalize, tap } from 'rxjs';
import { RequestActivity } from './request-activity';

/**
 * Marks a request the person did not wait for (a preference written as they click, a background refresh): it never
 * shows the activity bar, though a failure to reach the API is still noticed.
 */
export const SILENT = new HttpContextToken<boolean>(() => false);

/** Reports every API request to RequestActivity: when it starts and ends, and how it failed. */
export const activityInterceptor: HttpInterceptorFn = (request, next) => {
  if (!request.url.startsWith('/api/')) return next(request);
  const activity = inject(RequestActivity);
  const done = request.context.get(SILENT) ? () => undefined : activity.started();
  return next(request).pipe(
    tap({
      next: (event) => {
        if (event instanceof HttpResponse) activity.reached();
      },
      error: (error: unknown) => {
        if (error instanceof HttpErrorResponse) activity.failed(error.status, request.url);
      },
    }),
    finalize(done),
  );
};
