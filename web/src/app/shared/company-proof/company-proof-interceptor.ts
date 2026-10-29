// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse, type HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { catchError, from, switchMap, throwError } from 'rxjs';
import { CompanyEntry } from './company-entry';

/** What the API answers to a request in a company this sign-in has not proved itself for yet. */
const PROOF_REQUIRED = 'company_proof_required';

/**
 * The API's refusal to act in a company this sign-in has not proved itself for, in either of its shapes: API Platform
 * writes it as a problem's `detail`, a plain controller as the `error` code.
 */
export function companyProofRequired(error: unknown): boolean {
  if (!(error instanceof HttpErrorResponse) || error.status !== 403) return false;
  const body: unknown = error.error;
  if (typeof body !== 'object' || body === null) return false;
  const { detail, error: code } = body as { detail?: unknown; error?: unknown };
  return code === PROOF_REQUIRED || detail === PROOF_REQUIRED;
}

/**
 * A request refused for a proof asks for it, once for every request refused together, and is sent again once it is
 * given: a proof that lapsed while a page was open costs the person the proof, never what they were doing. Turned
 * down, the refusal reaches whoever asked, as any other.
 */
export const companyProofInterceptor: HttpInterceptorFn = (request, next) => {
  if (!request.url.startsWith('/api/')) return next(request);
  const entry = inject(CompanyEntry);
  return next(request).pipe(
    catchError((error: unknown) =>
      companyProofRequired(error)
        ? from(entry.prove()).pipe(
            switchMap((given) => (given ? next(request) : throwError(() => error))),
          )
        : throwError(() => error),
    ),
  );
};
