// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse, type HttpInterceptorFn } from '@angular/common/http';
import { inject, Injector } from '@angular/core';
import { TranslateService } from '@ngx-translate/core';
import { tap } from 'rxjs';
import { Feedback } from '../feedback/feedback';
import { refusalsIn } from '../form/field-refusals';
import { FormatFacade } from '../i18n/format-facade';

/**
 * The numbering refusals a computer clock set back causes: a day before the series' last number's, within its month or
 * in an earlier one. Every document that takes a number meets them (an invoice, a credit note, a delivery note, a quote,
 * an order), whichever screen issues it.
 */
const CLOCK_REFUSALS = new Set(['before_last_day', 'before_last_period']);

/**
 * The numbering refusals of a series: the establishment numbers no such document, or the number its series gave is on
 * another document already. Said only for the write that hit them (an issue, a validation, a send): a draft's next
 * number read meanwhile shows none and says nothing, issuing says why.
 */
const SERIES_REFUSALS = new Set(['no_numbering_series', 'series_number_taken']);

/** The answers said here, which `saidAlready` reads: only what was said in words, never a refusal left quiet. */
const saidHere = new WeakSet<HttpErrorResponse>();

/**
 * Says a numbering refused in words, wherever the document was issued from: for the computer's clock, the day the last
 * number was given and the day the clock reads, as the screen writes days, and what to check; for a series, the
 * establishment, the document as the numbering settings name it, and the number another document carries. `saidAlready` then tells
 * the screen not to say it again as a conflict, so it is said as a failure, kept until closed. The format is read when a refusal arrives, never when the interceptor
 * is built: it needs the session, whose own requests pass through here.
 */
export const numberingRefusedInterceptor: HttpInterceptorFn = (request, next) => {
  const injector = inject(Injector);
  return next(request).pipe(
    tap({
      error: (error: unknown) => {
        const refused = numberingRefusalOf(error, request.method);
        if (refused === null) return;
        injector
          .get(Feedback)
          .failure(`feedback.numbering.${refused.reason}`, said(refused, injector));
        if (error instanceof HttpErrorResponse) saidHere.add(error);
      },
    }),
  );
};

/** Whether an error is a numbering refusal this interceptor has said in words. */
export function isNumberingRefused(error: unknown): boolean {
  return error instanceof HttpErrorResponse && saidHere.has(error);
}

interface NumberingRefusal {
  readonly reason: string;
  readonly params: Readonly<Record<string, number | string>>;
}

function numberingRefusalOf(error: unknown, method: string): NumberingRefusal | null {
  if (!(error instanceof HttpErrorResponse) || (error.status !== 409 && error.status !== 422))
    return null;
  const refusal = refusalsIn(error.error).find(
    (one) =>
      one.reason !== null &&
      ((one.field === 'issueDate' && CLOCK_REFUSALS.has(one.reason)) ||
        (method !== 'GET' && SERIES_REFUSALS.has(one.reason))),
  );
  return refusal?.reason ? { reason: refusal.reason, params: refusal.params } : null;
}

/** A refusal's parameters as the screen writes them: days in its format, a document type in its words. */
function said(refused: NumberingRefusal, injector: Injector): Record<string, number | string> {
  const format = injector.get(FormatFacade);
  const texts = injector.get(TranslateService);
  return Object.fromEntries(
    Object.entries(refused.params).map(([name, value]) => [
      name,
      name === 'documentType'
        ? String(texts.instant(`company.numbering.document_types.${String(value)}`))
        : /^\d{4}-\d{2}-\d{2}$/.test(String(value))
          ? format.day(String(value))
          : value,
    ]),
  );
}
