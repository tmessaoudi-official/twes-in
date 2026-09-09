// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpInterceptorFn } from '@angular/common/http';

/**
 * Symfony stateless CSRF, header only (api/config/packages/framework.yaml): every request to the API carries a
 * random value of at least 24 characters in the csrf-token header. A cross-site page cannot set that header,
 * and the API also checks the origin. One value per page load is all the framework asks for.
 */
export const CSRF_HEADER = 'csrf-token';

let token: string | null = null;

export function csrfToken(): string {
  token ??= randomToken();
  return token;
}

function randomToken(): string {
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

export const csrfInterceptor: HttpInterceptorFn = (request, next) =>
  request.url.startsWith('/api/')
    ? next(request.clone({ setHeaders: { [CSRF_HEADER]: csrfToken() } }))
    : next(request);
