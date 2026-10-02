// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpInterceptorFn } from '@angular/common/http';
import { CSRF_HEADER, csrfToken } from '../shared/session/csrf-token';

export { CSRF_HEADER, csrfToken };

/** Puts the page's CSRF value on every request to the API. */
export const csrfInterceptor: HttpInterceptorFn = (request, next) =>
  request.url.startsWith('/api/')
    ? next(request.clone({ setHeaders: { [CSRF_HEADER]: csrfToken() } }))
    : next(request);
