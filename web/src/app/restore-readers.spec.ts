// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ExpensesApi } from './expenses/expenses-api';
import { InventoryApi } from './inventory/inventory-api';
import { QuotesApi } from './quotes/quotes-api';
import { TextsParts } from './shared/i18n/texts-part';

/**
 * A file put back where it was is refused one way on every record that keeps files: a 422 is the record full, said by
 * the restore's own code (`files_full`), never by the reason's words, which are an upload's (« before adding
 * another »); a 409 names the move the record refuses, read as every reader reads it. Each reader goes through
 * `restoreRefusalsIn` (`shared/files/restore-refusals-api.ts`), which `scripts/gates/restore-refusals.sh` holds.
 */

interface Reader {
  readonly name: string;
  readonly restore: () => Promise<unknown>;
  readonly url: string;
}

const READERS: readonly Reader[] = [
  {
    name: 'an expense',
    restore: () => TestBed.inject(ExpensesApi).restoreAttachment('c1', 'r1', 'f1'),
    url: '/api/companies/c1/expenses/r1/attachments/f1/restore',
  },
  {
    name: 'a quote',
    restore: () => TestBed.inject(QuotesApi).restoreAttachment('c1', 'r1', 'f1'),
    url: '/api/companies/c1/quotes/r1/attachments/f1/restore',
  },
  {
    name: 'a loss',
    restore: () => TestBed.inject(InventoryApi).restoreToLoss('c1', 'r1', 'f1'),
    url: '/api/companies/c1/stock-movements/r1/attachments/f1/restore',
  },
];

const refusal = (reason: string) => ({
  violations: [{ propertyPath: '', message: 'x', payload: { reason, params: { max: 10 } } }],
});

describe('a file put back', () => {
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: TextsParts, useValue: { open: () => Promise.resolve() } },
      ],
    });
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it.each(READERS)('on $name that is full is said by its code alone', async ({ restore, url }) => {
    const put = restore();
    http
      .expectOne({ method: 'POST', url })
      .flush(refusal('too_many_files'), { status: 422, statusText: 'Unprocessable Entity' });
    await expect(put).rejects.toMatchObject({ code: 'files_full', refusals: [] });
  });

  it.each(READERS)('on $name that refuses the move names why', async ({ restore, url }) => {
    const put = restore();
    http
      .expectOne({ method: 'POST', url })
      .flush(refusal('record_closed'), { status: 409, statusText: 'Conflict' });
    // The code is each feature's own word for a 409; the refusal is read alike.
    await expect(put).rejects.toMatchObject({
      refusals: [{ field: '', reason: 'record_closed', params: { max: 10 } }],
    });
  });
});
