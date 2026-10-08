// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse } from '@angular/common/http';
import { isPeriodClosed } from './period-closed';

describe('isPeriodClosed', () => {
  const refusal = (status: number, detail: unknown) =>
    new HttpErrorResponse({ status, error: { detail } });

  it('reads the closed books from the refusal, whatever the document', () => {
    expect(
      isPeriodClosed(refusal(422, 'closedPeriod: the books are closed through 2026-09-30')),
    ).toBe(true);
    expect(isPeriodClosed(refusal(422, 'date: Money is received today at the latest'))).toBe(false);
    expect(isPeriodClosed(refusal(409, 'closedPeriod: no'))).toBe(false);
    expect(isPeriodClosed(new Error('closedPeriod:'))).toBe(false);
  });
});
