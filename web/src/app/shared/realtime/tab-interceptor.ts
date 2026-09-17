// SPDX-License-Identifier: AGPL-3.0-or-later

import type { HttpInterceptorFn } from '@angular/common/http';

/**
 * The header naming the tab that made a request. The API echoes it as `origin` in the change it announces
 * (docs/SPEC.md § 7, 2026-09-17), so this tab can tell the echo of its own change, which it already shows, from
 * somebody else's.
 */
export const TAB_HEADER = 'X-Tab';

let id: string | null = null;

/** This tab's name for the whole page load. */
export function tabId(): string {
  id ??= crypto.randomUUID();
  return id;
}

export const tabInterceptor: HttpInterceptorFn = (request, next) =>
  request.url.startsWith('/api/')
    ? next(request.clone({ setHeaders: { [TAB_HEADER]: tabId() } }))
    : next(request);
