// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { noticeToasts, provideQuietFeedback } from '../testing/feedback';
import { submissionInterceptor } from './submission-interceptor';
import {
  SUBMISSION_IN_PROGRESS,
  SUBMISSION_KEY_HEADER,
  SUBMISSION_KEY_KEPT_MS,
  SUBMISSION_UNANSWERED,
  SubmissionKeys,
  writeOf,
} from './submission-keys';

const PAYMENTS = '/api/companies/a/invoices/i1/payments';
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

describe('submissionInterceptor', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([submissionInterceptor])),
        provideHttpClientTesting(),
        provideQuietFeedback(),
      ],
    });
  });

  /** Sends a payment of `amount`, answers it with `status` (0 for no answer at all), and gives the key it carried. */
  function pay(amount: string, status: number, body: object = {}): string | null {
    TestBed.inject(HttpClient)
      .post(PAYMENTS, { amount })
      .subscribe({ error: () => undefined });
    const sent = TestBed.inject(HttpTestingController).expectOne(PAYMENTS);
    const key = sent.request.headers.get(SUBMISSION_KEY_HEADER);
    if (status === 0) sent.error(new ProgressEvent('error'));
    else if (status < 400) sent.flush(body, { status, statusText: 'OK' });
    else sent.flush(body, { status, statusText: 'Refused' });
    return key;
  }

  it('names a write sent to the API with a key, and nothing else', () => {
    const http = TestBed.inject(HttpClient);
    const controller = TestBed.inject(HttpTestingController);

    http.post(PAYMENTS, { amount: '40' }).subscribe();
    http.get('/api/companies/a/invoices').subscribe();
    http.put('/api/companies/a/invoices/i1', {}).subscribe();
    http.post('/i18n/fr.json', {}).subscribe();

    expect(controller.expectOne(PAYMENTS).request.headers.get(SUBMISSION_KEY_HEADER)).toMatch(UUID);
    expect(
      controller.expectOne('/api/companies/a/invoices').request.headers.has(SUBMISSION_KEY_HEADER),
    ).toBe(false);
    expect(
      controller
        .expectOne('/api/companies/a/invoices/i1')
        .request.headers.has(SUBMISSION_KEY_HEADER),
    ).toBe(false);
    expect(controller.expectOne('/i18n/fr.json').request.headers.has(SUBMISSION_KEY_HEADER)).toBe(
      false,
    );
  });

  it('sends the same write again with the same key while no answer came, the API failed or it is still being made', () => {
    const first = pay('40', 0);
    const afterServerError = pay('40', 503);
    const afterInProgress = pay('40', 409, { type: SUBMISSION_IN_PROGRESS });
    const afterThat = pay('40', 201);

    expect(new Set([first, afterServerError, afterInProgress, afterThat]).size).toBe(1);
  });

  it('names the next write anew once the API answered the last one for good', () => {
    const made = pay('40', 201);
    const refused = pay('40', 422);
    const afterRefusal = pay('40', 0);
    const afterUnanswered = pay('40', 409, { type: SUBMISSION_UNANSWERED });
    const afterConflict = pay('40', 0);

    expect(pay('40', 409, { type: '/problems/record-changed' })).toBe(afterConflict);
    expect(new Set([made, refused, afterRefusal, afterConflict]).size).toBe(4);
    expect(afterUnanswered).toBe(afterRefusal);
    expect(pay('40', 0)).not.toBe(afterConflict);
  });

  it('gives another write its own key even while the first is unanswered', () => {
    expect(pay('40', 0)).not.toBe(pay('50', 0));
  });

  it('says a write still being made and a write made whose answer was lost, wherever it was sent from', () => {
    pay('40', 409, { type: SUBMISSION_IN_PROGRESS });
    pay('40', 409, { type: SUBMISSION_UNANSWERED });
    pay('40', 409, { type: '/problems/record-changed' });
    pay('40', 422, { type: SUBMISSION_IN_PROGRESS });

    expect(noticeToasts().map((toast) => toast.key)).toEqual([
      'feedback.submission_in_progress',
      'feedback.submission_unanswered',
    ]);
  });
});

describe('SubmissionKeys', () => {
  it('forgets a key whose answer never came once its time is over', () => {
    const keys = new SubmissionKeys();
    const write = writeOf(PAYMENTS, { amount: '40' });
    const key = keys.keyFor(write, 0);

    expect(keys.keyFor(write, SUBMISSION_KEY_KEPT_MS)).toBe(key);
    expect(keys.keyFor(write, 2 * SUBMISSION_KEY_KEPT_MS + 1)).not.toBe(key);
  });

  it('reads a file picked again as the same write, and another file as another', () => {
    const form = (file: File): FormData => {
      const data = new FormData();
      data.append('mode', 'create');
      data.append('file', file);
      return data;
    };
    const picked = (name: string): File => new File(['a;b'], name, { lastModified: 1 });

    expect(writeOf('/api/x', form(picked('clients.csv')))).toBe(
      writeOf('/api/x', form(picked('clients.csv'))),
    );
    expect(writeOf('/api/x', form(picked('clients.csv')))).not.toBe(
      writeOf('/api/x', form(picked('produits.csv'))),
    );
  });

  it('never reads a body it cannot compare as the same write twice', () => {
    const blob = new Blob(['a']);

    expect(writeOf('/api/x', blob)).not.toBe(writeOf('/api/x', blob));
    expect(writeOf('/api/x', new Uint8Array([1]))).not.toBe(writeOf('/api/x', new Uint8Array([1])));
  });
});
