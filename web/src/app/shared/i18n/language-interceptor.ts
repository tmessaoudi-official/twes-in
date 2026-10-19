// SPDX-License-Identifier: AGPL-3.0-or-later

import type { HttpInterceptorFn } from '@angular/common/http';
import { DOCUMENT, inject } from '@angular/core';

/**
 * Asks the API for the language the screen shows, so the words it puts on a screen (a preset's identifier and regime
 * labels) are in that language, not the browser's own nor the one last saved on the account, which only mails read.
 * The document's `lang` is the screen's language: `LanguageFacade` moves it once a language's texts are loaded. It is
 * read rather than the facade, which reads the settings over HTTP and would wait on itself here.
 */
export const languageInterceptor: HttpInterceptorFn = (request, next) => {
  const language = inject(DOCUMENT).documentElement.lang;
  return request.url.startsWith('/api/') && language !== ''
    ? next(request.clone({ setHeaders: { 'Accept-Language': language } }))
    : next(request);
};
