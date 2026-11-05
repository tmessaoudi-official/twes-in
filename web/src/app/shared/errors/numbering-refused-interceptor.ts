// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse, type HttpInterceptorFn } from '@angular/common/http';
import { inject, Injector } from '@angular/core';
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
 * Says a numbering refused for the computer's clock in words, wherever the document was issued from: the day the last
 * number was given and the day the clock reads, as the screen writes days, and what to check. `saidAlready` then tells
 * the screen not to say it again as a conflict, so it is said as a failure, kept until closed. The format is read when a refusal arrives, never when the interceptor
 * is built: it needs the session, whose own requests pass through here.
 */
export const numberingRefusedInterceptor: HttpInterceptorFn = (request, next) => {
  const injector = inject(Injector);
  return next(request).pipe(
    tap({
      error: (error: unknown) => {
        const refused = clockRefusalOf(error);
        if (refused === null) return;
        const format = injector.get(FormatFacade);
        const days = Object.fromEntries(
          Object.entries(refused.params).map(([name, value]) => [
            name,
            /^\d{4}-\d{2}-\d{2}$/.test(String(value)) ? format.day(String(value)) : value,
          ]),
        );
        injector.get(Feedback).failure(`feedback.numbering.${refused.reason}`, days);
      },
    }),
  );
};

/** Whether an error is a numbering refused for the computer's clock. */
export function isNumberingRefused(error: unknown): boolean {
  return clockRefusalOf(error) !== null;
}

function clockRefusalOf(
  error: unknown,
): { reason: string; params: Readonly<Record<string, number | string>> } | null {
  if (!(error instanceof HttpErrorResponse) || (error.status !== 409 && error.status !== 422))
    return null;
  const refusal = refusalsIn(error.error).find(
    (one) => one.field === 'issueDate' && one.reason !== null && CLOCK_REFUSALS.has(one.reason),
  );
  return refusal?.reason ? { reason: refusal.reason, params: refusal.params } : null;
}
