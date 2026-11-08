// SPDX-License-Identifier: AGPL-3.0-or-later

import { refusalsIn } from '../form/field-refusals';
import { answersUnder } from './said-already';

/**
 * The reason the API gives a list asked for a filter value it has not: an address kept from before, or typed by hand.
 * Reading the list again would meet the same refusal, so it says to clear the filters instead.
 */
export const FILTER_REFUSED = 'filter_refused';

/** Whether a read failed because a filter it asked for is refused, bare or under the refusal a feature built from it. */
export function isFilterRefused(error: unknown): boolean {
  return answersUnder(error).some((seen) => {
    const answer = refusedAnswer(seen);
    return (
      answer !== null &&
      refusalsIn(answer.error).some((refusal) => refusal.reason === FILTER_REFUSED)
    );
  });
}

/**
 * Angular's HttpErrorResponse answering 422, known by the name it carries, as the error handler knows one: only an API
 * adapter may import Angular's HTTP layer.
 */
function refusedAnswer(seen: unknown): { readonly error: unknown } | null {
  if (typeof seen !== 'object' || seen === null) return null;
  const { name, status, error } = seen as { name?: unknown; status?: unknown; error?: unknown };
  return name === 'HttpErrorResponse' && status === 422 ? { error } : null;
}
