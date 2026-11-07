// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideTranslateService, TranslateService } from '@ngx-translate/core';
import { FormatFacade } from '../i18n/format-facade';
import { failuresSaid, noticeToasts, provideQuietFeedback } from '../testing/feedback';
import { isNumberingRefused, numberingRefusedInterceptor } from './numbering-refused-interceptor';
import { saidAlready } from './said-already';

const ISSUE = '/api/companies/a/invoices/i1/issue';
const NEXT_NUMBER = '/api/companies/a/invoices/i1/next-number';

function refusal(reason: string, params: Record<string, string>, field = 'issueDate'): object {
  return {
    status: 409,
    violations: [
      {
        propertyPath: field,
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
        provideTranslateService({ lang: 'fr' }),
        { provide: FormatFacade, useValue: { day: (value: string) => `day ${value}` } },
      ],
    });
    TestBed.inject(TranslateService).setTranslation('fr', {
      company: { numbering: { document_types: { quote: 'Devis', credit_note: 'Avoir' } } },
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

      // A refusal of the pressed action stays until closed: the screen says nothing of its own (`saidAlready`).
      const toasts = failuresSaid();
      expect(toasts.map((toast) => toast.key)).toEqual([key]);
      expect(noticeToasts()).toEqual([]);
      expect(toasts[0]?.params?.['today']).toBe(`day ${params.today}`);
      expect(isNumberingRefused(failure)).toBe(true);
      expect(saidAlready(failure)).toBe(true);
    },
  );

  it.each([
    [
      'no_numbering_series',
      { establishment: 'MAIN', documentType: 'quote' },
      { establishment: 'MAIN', documentType: 'Devis' },
    ],
    [
      'series_number_taken',
      { number: 'AV-2026-0007', establishment: 'SFAX', documentType: 'credit_note' },
      { number: 'AV-2026-0007', establishment: 'SFAX', documentType: 'Avoir' },
    ],
  ])(
    'says a document its numbering refused (%s) in words, naming the document as the numbering settings do',
    async (reason, params, said) => {
      const failure = issueAnswered(refusal(reason, params, ''));
      await Promise.resolve();

      const toasts = failuresSaid();
      expect(toasts.map((toast) => toast.key)).toEqual([`feedback.numbering.${reason}`]);
      expect(toasts[0]?.params).toEqual(said);
      expect(isNumberingRefused(failure)).toBe(true);
      expect(saidAlready(failure)).toBe(true);
    },
  );

  it('says nothing when only the next number of a draft is read: issuing says it', async () => {
    let failure: unknown = null;
    TestBed.inject(HttpClient)
      .get(NEXT_NUMBER)
      .subscribe({ error: (error: unknown) => (failure = error) });
    TestBed.inject(HttpTestingController)
      .expectOne(NEXT_NUMBER)
      .flush(refusal('no_numbering_series', { establishment: 'MAIN', documentType: 'quote' }, ''), {
        status: 409,
        statusText: 'Refused',
      });
    await Promise.resolve();

    expect(failuresSaid()).toEqual([]);
    expect(saidAlready(failure)).toBe(false);
  });

  it('leaves any other refusal, and any other numbering refusal, to the screen', async () => {
    const other = issueAnswered(refusal('sequence_from', { next: '4' }));
    const unrelated = issueAnswered({ type: '/problems/record-changed' });
    await Promise.resolve();

    expect(noticeToasts()).toEqual([]);
    expect(failuresSaid()).toEqual([]);
    expect(isNumberingRefused(other)).toBe(false);
    expect(saidAlready(unrelated)).toBe(false);
  });
});
