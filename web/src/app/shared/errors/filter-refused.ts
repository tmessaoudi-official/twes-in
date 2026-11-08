// SPDX-License-Identifier: AGPL-3.0-or-later

import { refusalsIn } from '../form/field-refusals';
import { answersUnder } from './said-already';

/**
 * The reason the API gives a list asked for a filter value it has not: an address kept from before, or typed by hand.
 * Reading the list again would meet the same refusal, so it says to clear the filters instead.
 */
export const FILTER_REFUSED = 'filter_refused';

/**
 * What a read refused for a filter asked: the API's parameters, each once, without an entry's index or an interval's
 * end (`taxRegime[0]`, `issueDate[from]` and `totalGross.max` name `taxRegime`, `issueDate`, `totalGross`). A list
 * finds the filter a person set from them, since the API names a parameter as it reads it, not as the list does.
 */
export interface RefusedFilter {
  readonly asked: readonly string[];
}

/** Whether a read failed because a filter it asked for is refused, bare or under the refusal a feature built from it. */
export function isFilterRefused(error: unknown): boolean {
  return refusedFilterIn(error) !== null;
}

/** The filters a read was refused for, bare or under the refusal a feature built from it; null for any other failure. */
export function refusedFilterIn(error: unknown): RefusedFilter | null {
  for (const seen of answersUnder(error)) {
    const answer = refusedAnswer(seen);
    if (answer === null) continue;
    const refused = refusalsIn(answer.error).filter((refusal) => refusal.reason === FILTER_REFUSED);
    if (refused.length > 0) {
      return { asked: [...new Set(refused.map((refusal) => refusal.field.split(/[[.]/)[0]))] };
    }
  }
  return null;
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
