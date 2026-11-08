// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse } from '@angular/common/http';
import { FILTER_REFUSED, isFilterRefused, refusedFilterIn } from './filter-refused';
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

describe('refusedFilterIn', () => {
  function refusedAt(...paths: readonly string[]): HttpErrorResponse {
    return new HttpErrorResponse({
      status: 422,
      error: {
        violations: paths.map((propertyPath) => ({
          propertyPath,
          message: 'x',
          payload: { reason: FILTER_REFUSED },
        })),
      },
    });
  }

  it('names the parameters the API refused, without an entry index or an interval end', () => {
    expect(refusedFilterIn(refusedAt('taxRegime[0]'))).toEqual({ asked: ['taxRegime'] });
    expect(refusedFilterIn(refusedAt('issueDate[from]', 'status'))).toEqual({
      asked: ['issueDate', 'status'],
    });
    expect(refusedFilterIn(refusedAt('totalGross.max'))).toEqual({ asked: ['totalGross'] });
  });

  it('reads them under a refusal a feature built, and only the filter refusals of the answer', () => {
    const answer = new HttpErrorResponse({
      status: 422,
      error: {
        violations: [
          { propertyPath: 'kind', payload: { reason: 'invalid_value' } },
          { propertyPath: 'customerGroupId[1]', payload: { reason: FILTER_REFUSED } },
        ],
      },
    });

    expect(refusedFilterIn(refusedFor(answer, new Refused('invalid')))).toEqual({
      asked: ['customerGroupId'],
    });
  });

  it('says one name once, however many of its entries were refused', () => {
    expect(refusedFilterIn(refusedAt('kind[0]', 'kind[1]'))).toEqual({ asked: ['kind'] });
  });

  it('is null for every failure that is not a filter refused', () => {
    expect(refusedFilterIn(refusedOn('invalid_value'))).toBeNull();
    expect(refusedFilterIn(new HttpErrorResponse({ status: 0 }))).toBeNull();
    expect(refusedFilterIn(null)).toBeNull();
  });
});
