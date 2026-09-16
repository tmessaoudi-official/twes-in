// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { of, Subject } from 'rxjs';
import { type ApiStatus, HealthApi } from '../health/health-api';
import { Feedback } from './feedback';
import { BUSY_AFTER_MS, RequestActivity, RETRY_DELAYS_S, SLOW_AFTER_MS } from './request-activity';

describe('RequestActivity', () => {
  let health: Subject<ApiStatus>;
  let checks: number;
  const feedback = { success: vi.fn(), failure: vi.fn() };

  beforeEach(() => {
    vi.useFakeTimers();
    feedback.success.mockReset();
    checks = 0;
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [
        { provide: Feedback, useValue: feedback },
        {
          provide: HealthApi,
          useValue: {
            status: () => {
              checks++;
              health = new Subject<ApiStatus>();
              return health;
            },
          },
        },
      ],
    });
  });

  afterEach(() => vi.useRealTimers());

  const activity = () => TestBed.inject(RequestActivity);

  it('says nothing for a request that answers quickly, so a fast page never flickers', () => {
    const done = activity().started();
    vi.advanceTimersByTime(BUSY_AFTER_MS - 1);
    expect(activity().busy()).toBe(false);
    done();
    vi.advanceTimersByTime(BUSY_AFTER_MS);
    expect(activity().busy()).toBe(false);
  });

  it('shows it is working once a request has taken 300 ms, and says so again when it is slow', () => {
    const done = activity().started();
    vi.advanceTimersByTime(BUSY_AFTER_MS);
    expect(activity().busy()).toBe(true);
    expect(activity().slow()).toBe(false);

    vi.advanceTimersByTime(SLOW_AFTER_MS - BUSY_AFTER_MS);
    expect(activity().slow()).toBe(true);

    done();
    expect(activity().busy()).toBe(false);
    expect(activity().slow()).toBe(false);
  });

  it('stays busy until the last of several requests ends, and counts a finished request once', () => {
    const first = activity().started();
    const second = activity().started();
    vi.advanceTimersByTime(BUSY_AFTER_MS);
    first();
    first();
    expect(activity().busy()).toBe(true);
    second();
    expect(activity().busy()).toBe(false);
  });

  it('turns unreachable on a network failure, retries the health check with growing waits, and says when it is back', () => {
    activity().failed(0, '/api/customers');
    expect(activity().unavailable()).toBe(true);
    expect(activity().retryIn()).toBe(RETRY_DELAYS_S[0]);

    vi.advanceTimersByTime(1000);
    expect(activity().retryIn()).toBe(RETRY_DELAYS_S[0] - 1);
    vi.advanceTimersByTime((RETRY_DELAYS_S[0] - 1) * 1000);
    expect(checks).toBe(1);
    health.next('unreachable');
    expect(activity().unavailable()).toBe(true);
    expect(activity().retryIn()).toBe(RETRY_DELAYS_S[1]);

    vi.advanceTimersByTime(RETRY_DELAYS_S[1] * 1000);
    expect(checks).toBe(2);
    health.next('ok');
    expect(activity().unavailable()).toBe(false);
    expect(activity().retryIn()).toBeNull();
    expect(feedback.success).toHaveBeenCalledWith('feedback.restored');
  });

  it('treats a gateway that answers for a down API as unreachable, and any other refusal as proof the API is there', () => {
    for (const status of [502, 503, 504]) {
      TestBed.resetTestingModule();
      TestBed.configureTestingModule({
        providers: [
          { provide: Feedback, useValue: feedback },
          { provide: HealthApi, useValue: { status: () => of('ok') } },
        ],
      });
      activity().failed(status, '/api/invoices');
      expect(activity().unavailable(), String(status)).toBe(true);
    }
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [
        { provide: Feedback, useValue: feedback },
        { provide: HealthApi, useValue: { status: () => of('ok') } },
      ],
    });
    activity().failed(422, '/api/invoices');
    activity().failed(500, '/api/invoices');
    expect(activity().unavailable()).toBe(false);
  });

  it('checks at once when asked to retry now', () => {
    activity().failed(0, '/api/customers');
    activity().retryNow();
    expect(checks).toBe(1);
    health.next('ok');
    expect(activity().unavailable()).toBe(false);
  });

  it('is back as soon as any API response arrives while it waits', () => {
    activity().failed(0, '/api/customers');
    activity().reached();
    expect(activity().unavailable()).toBe(false);
    expect(feedback.success).toHaveBeenCalledWith('feedback.restored');
    vi.advanceTimersByTime(60_000);
    expect(checks).toBe(0);
  });

  it('follows the browser going offline and online, checking again when it returns', () => {
    window.dispatchEvent(new Event('offline'));
    const tracked = activity();
    window.dispatchEvent(new Event('offline'));
    expect(tracked.offline()).toBe(true);
    window.dispatchEvent(new Event('online'));
    expect(tracked.offline()).toBe(false);
  });

  it('notices a session that ended while the page was open, but not a refused sign-in', () => {
    activity().failed(401, '/api/auth/login');
    activity().failed(401, '/api/auth/me');
    expect(activity().sessionExpired()).toBe(false);

    activity().failed(401, '/api/companies/c1/customers');
    expect(activity().sessionExpired()).toBe(true);
    activity().acknowledgeExpiry();
    expect(activity().sessionExpired()).toBe(false);
  });
});
