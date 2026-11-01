// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  HttpClient,
  HttpErrorResponse,
  provideHttpClient,
  withInterceptors,
} from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { noticeToasts, provideQuietFeedback } from '../testing/feedback';
import { BUSY, busyInterceptor, isBusy } from './busy-interceptor';
import { saidAlready } from './said-already';

const CUSTOMERS = '/api/companies/a/customers';
const BUSY_BODY = { type: BUSY, title: 'Busy', status: 409, detail: 'Send it again.' };

describe('busyInterceptor', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([busyInterceptor])),
        provideHttpClientTesting(),
        provideQuietFeedback(),
      ],
    });
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  function busy(request: ReturnType<HttpTestingController['expectOne']>): void {
    request.flush(BUSY_BODY, {
      status: 409,
      statusText: 'Conflict',
      headers: { 'Retry-After': '1' },
    });
  }

  it('sends a write the database was busy for once more after its Retry-After, unseen', () => {
    const controller = TestBed.inject(HttpTestingController);
    let answer: unknown = null;
    TestBed.inject(HttpClient)
      .post(CUSTOMERS, { number: 'CLI-0001' })
      .subscribe((body) => (answer = body));

    busy(controller.expectOne(CUSTOMERS));
    controller.expectNone(CUSTOMERS);
    vi.advanceTimersByTime(1_000);
    const again = controller.expectOne(CUSTOMERS);
    expect(again.request.body).toEqual({ number: 'CLI-0001' });
    again.flush({ id: 'c1' }, { status: 201, statusText: 'Created' });

    expect(answer).toEqual({ id: 'c1' });
    expect(noticeToasts()).toEqual([]);
  });

  it('says it in words when the second try is busy too, and the screen says nothing more', () => {
    const controller = TestBed.inject(HttpTestingController);
    let failure: unknown = null;
    TestBed.inject(HttpClient)
      .post(CUSTOMERS, {})
      .subscribe({ error: (error: unknown) => (failure = error) });

    busy(controller.expectOne(CUSTOMERS));
    vi.advanceTimersByTime(1_000);
    busy(controller.expectOne(CUSTOMERS));
    controller.expectNone(CUSTOMERS);

    expect(noticeToasts().map((toast) => toast.key)).toEqual(['feedback.busy']);
    expect(isBusy(failure)).toBe(true);
    expect(saidAlready(failure)).toBe(true);
  });

  it('leaves any other 409 to the screen, untried again', () => {
    const controller = TestBed.inject(HttpTestingController);
    let failure: unknown = null;
    TestBed.inject(HttpClient)
      .post(CUSTOMERS, {})
      .subscribe({ error: (error: unknown) => (failure = error) });

    controller
      .expectOne(CUSTOMERS)
      .flush({ type: '/problems/record-changed' }, { status: 409, statusText: 'Conflict' });
    vi.advanceTimersByTime(5_000);
    controller.expectNone(CUSTOMERS);

    expect(failure).toBeInstanceOf(HttpErrorResponse);
    expect(isBusy(failure)).toBe(false);
    expect(saidAlready(failure)).toBe(false);
    expect(noticeToasts()).toEqual([]);
  });
});
