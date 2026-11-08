// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse } from '@angular/common/http';
import { FILTER_REFUSED, isFilterRefused } from './filter-refused';
import { refusedFor } from './said-already';

class Refused extends Error {}

function refusedOn(...reasons: readonly (string | null)[]): HttpErrorResponse {
  return new HttpErrorResponse({
    status: 422,
    error: {
      violations: reasons.map((reason, index) => ({
        propertyPath: `filter${index}`,
        message: 'x',
        ...(reason === null ? {} : { payload: { reason } }),
      })),
    },
  });
}

describe('isFilterRefused', () => {
  it('reads a list refused for a filter, bare or kept under a refusal', () => {
    const answer = refusedOn(FILTER_REFUSED);

    expect(isFilterRefused(answer)).toBe(true);
    expect(isFilterRefused(refusedFor(answer, new Refused('invalid')))).toBe(true);
    expect(
      isFilterRefused(refusedFor(refusedFor(answer, new Refused('a')), new Refused('b'))),
    ).toBe(true);
  });

  it('reads it among other refusals of the same answer', () => {
    expect(isFilterRefused(refusedOn('invalid_value', FILTER_REFUSED))).toBe(true);
  });

  it('leaves every other failure to the list, which offers to try again', () => {
    expect(isFilterRefused(refusedOn('invalid_value'))).toBe(false);
    expect(isFilterRefused(refusedOn(null))).toBe(false);
    expect(isFilterRefused(new HttpErrorResponse({ status: 422, error: {} }))).toBe(false);
    expect(
      isFilterRefused(
        new HttpErrorResponse({
          status: 400,
          error: { violations: [{ propertyPath: 'status', payload: { reason: FILTER_REFUSED } }] },
        }),
      ),
    ).toBe(false);
    expect(isFilterRefused(new HttpErrorResponse({ status: 0 }))).toBe(false);
    expect(isFilterRefused(new Refused('invalid'))).toBe(false);
    expect(isFilterRefused(null)).toBe(false);
    expect(isFilterRefused(FILTER_REFUSED)).toBe(false);
  });

  it('stops on a refusal built from itself', () => {
    const looped = new Refused('invalid');

    expect(isFilterRefused(refusedFor(looped, looped))).toBe(false);
  });
});
