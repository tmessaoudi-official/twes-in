// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  HttpErrorResponse,
  type HttpHeaders,
  type HttpInterceptorFn,
  HttpResponse,
} from '@angular/common/http';
import { inject } from '@angular/core';
import { tap } from 'rxjs';
import { LiveChanges } from './live-changes';

/** The header in which the API names what a tab's request changed (`LiveChangesAnswer` on the API). */
export const LIVE_CHANGES_HEADER = 'X-Live-Changes';

/**
 * Hands this tab what its own request changed, before the caller reads the answer: every panel of the tab hears it as
 * it hears another tab's change, so the outcome shows at once wherever its subject appears. A refused answer may name
 * changes too, when the request failed after a unit of work had committed.
 */
export const liveChangesInterceptor: HttpInterceptorFn = (request, next) => {
  const live = inject(LiveChanges);
  const hear = (headers: HttpHeaders): void => {
    const named = headers.get(LIVE_CHANGES_HEADER);
    if (named !== null) live.heardHere(named);
  };
  return next(request).pipe(
    tap({
      next: (event) => {
        if (event instanceof HttpResponse) hear(event.headers);
      },
      error: (error: unknown) => {
        if (error instanceof HttpErrorResponse) hear(error.headers);
      },
    }),
  );
};
