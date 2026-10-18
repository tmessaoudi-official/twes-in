// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { catchError, Observable, of, throwError } from 'rxjs';
import type { LegalTextLegalTextRead } from '../../api/types.gen';
import { type Language, LEGAL_LANGUAGES, nameOf } from '../i18n/languages';

/** The languages a legal page is written in, which the catalogue marks `legal`. */
export { LEGAL_LANGUAGES };
export type LegalLanguage = Language;

/** Each language named in itself, as LANGUAGE_NAMES does for the interface's. */
export const LEGAL_LANGUAGE_NAMES: Readonly<Record<LegalLanguage, string>> = Object.fromEntries(
  LEGAL_LANGUAGES.map((language) => [language, nameOf(language)]),
);

export type LegalDocument = LegalTextLegalTextRead;

/**
 * GET /api/legal/<page>/<language>, open to anyone: the page's latest version, in that language or the one the API
 * fell back to. Null for a page never written; any other failure is the caller's to say.
 */
@Injectable({ providedIn: 'root' })
export class LegalApi {
  private readonly http = inject(HttpClient);

  read(page: string, language: LegalLanguage): Observable<LegalDocument | null> {
    return this.http
      .get<LegalDocument>(`/api/legal/${encodeURIComponent(page)}/${language}`, {
        headers: { Accept: 'application/json' },
      })
      .pipe(
        catchError((error: unknown) =>
          error instanceof HttpErrorResponse && error.status === 404
            ? of(null)
            : throwError(() => error),
        ),
      );
  }
}
