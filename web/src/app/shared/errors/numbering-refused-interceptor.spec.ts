// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { FormatFacade } from '../i18n/format-facade';
import { noticeToasts, provideQuietFeedback } from '../testing/feedback';
import { isNumberingRefused, numberingRefusedInterceptor } from './numbering-refused-interceptor';
import { saidAlready } from './said-already';

const ISSUE = '/api/companies/a/invoices/i1/issue';

function refusal(reason: string, params: Record<string, string>): object {
  return {
    status: 409,
    violations: [
      {
        propertyPath: 'issueDate',
        message: 'Check the clock.',
        code: reason,
        payload: { reason, params },
      },
    ],
  };
}

describe('numberingRefusedInterceptor', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([numberingRefusedInterceptor])),
        provideHttpClientTesting(),
        provideQuietFeedback(),
        { provide: FormatFacade, useValue: { day: (value: string) => `day ${value}` } },
      ],
    });
  });

  function issueAnswered(body: object, status = 409): unknown {
    let failure: unknown = null;
    TestBed.inject(HttpClient)
      .post(ISSUE, {})
      .subscribe({ error: (error: unknown) => (failure = error) });
    TestBed.inject(HttpTestingController)
      .expectOne(ISSUE)
      .flush(body, { status, statusText: 'Refused' });
    return failure;
  }

  it.each([
    [
      'before_last_day',
      'feedback.numbering.before_last_day',
      { last: '2026-11-15', today: '2026-11-14' },
    ],
    [
      'before_last_period',
      'feedback.numbering.before_last_period',
      { last: '2026-12-05', today: '2026-11-05' },
    ],
  ])(
    'says a document numbered on a day the clock set back (%s) in words, its days as the screen writes them',
    async (reason, key, params) => {
      const failure = issueAnswered(refusal(reason, params));
      await Promise.resolve();

      const toasts = noticeToasts();
      expect(toasts.map((toast) => toast.key)).toEqual([key]);
      expect(toasts[0]?.params?.['today']).toBe(`day ${params.today}`);
      expect(isNumberingRefused(failure)).toBe(true);
      expect(saidAlready(failure)).toBe(true);
    },
  );

  it('leaves any other refusal, and any other numbering refusal, to the screen', async () => {
    const other = issueAnswered(refusal('sequence_from', { next: '4' }));
    const unrelated = issueAnswered({ type: '/problems/record-changed' });
    await Promise.resolve();

    expect(noticeToasts()).toEqual([]);
    expect(isNumberingRefused(other)).toBe(false);
    expect(saidAlready(unrelated)).toBe(false);
  });
});
