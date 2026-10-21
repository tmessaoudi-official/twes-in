// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  HttpClient,
  HttpErrorResponse,
  provideHttpClient,
  withInterceptors,
} from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Feedback } from '../feedback/feedback';
import { demoRefusedInterceptor, isDemoRefused } from './demo-refused-interceptor';

describe('demoRefusedInterceptor', () => {
  const feedback = { failure: vi.fn() };

  beforeEach(() => {
    feedback.failure.mockReset();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([demoRefusedInterceptor])),
        provideHttpClientTesting(),
        { provide: Feedback, useValue: feedback },
      ],
    });
  });

  const http = () => TestBed.inject(HttpClient);
  const backend = () => TestBed.inject(HttpTestingController);

  it('says in words that the demo does not do it, and still hands the refusal to whoever asked', () => {
    const failed = vi.fn();
    http().post('/api/companies/c1/members', {}).subscribe({ error: failed });

    backend()
      .expectOne('/api/companies/c1/members')
      .flush({ error: 'demo_refused' }, { status: 403, statusText: 'Forbidden' });

    expect(feedback.failure).toHaveBeenCalledExactlyOnceWith('auth.errors.demo_refused');
    expect(failed).toHaveBeenCalledOnce();
  });

  it('leaves every other refusal to whoever asked', () => {
    http()
      .post('/api/companies/c1/members', {})
      .subscribe({ error: () => undefined });
    backend()
      .expectOne('/api/companies/c1/members')
      .flush({ error: 'support_read_only' }, { status: 403, statusText: 'Forbidden' });
    http()
      .put('/api/auth/password', {})
      .subscribe({ error: () => undefined });
    backend()
      .expectOne('/api/auth/password')
      .flush({ error: 'demo_refused' }, { status: 400, statusText: 'Bad Request' });

    expect(feedback.failure).not.toHaveBeenCalled();
  });

  it("reads a file download's refusal too, and hands it on as one a screen recognises", async () => {
    const failed = vi.fn();
    http()
      .get('/api/companies/c1/exports/sales-journal.csv', { responseType: 'blob' })
      .subscribe({ error: failed });

    backend()
      .expectOne('/api/companies/c1/exports/sales-journal.csv')
      .flush(new Blob([JSON.stringify({ error: 'demo_refused' })], { type: 'application/json' }), {
        status: 403,
        statusText: 'Forbidden',
      });
    await vi.waitFor(() => expect(failed).toHaveBeenCalledOnce());

    expect(feedback.failure).toHaveBeenCalledExactlyOnceWith('auth.errors.demo_refused');
    expect(isDemoRefused(failed.mock.calls[0]?.[0])).toBe(true);
  });

  it("leaves a file download's other refusal as it came, its body still to read", async () => {
    const failed = vi.fn();
    http()
      .get('/api/companies/c1/exports/customers.csv', { responseType: 'blob' })
      .subscribe({ error: failed });

    backend()
      .expectOne('/api/companies/c1/exports/customers.csv')
      .flush(new Blob([JSON.stringify({ error: 'step_up_required' })]), {
        status: 403,
        statusText: 'Forbidden',
      });
    await vi.waitFor(() => expect(failed).toHaveBeenCalledOnce());

    const error: unknown = failed.mock.calls[0]?.[0];
    expect(feedback.failure).not.toHaveBeenCalled();
    expect(isDemoRefused(error)).toBe(false);
    expect(error instanceof HttpErrorResponse && error.error instanceof Blob).toBe(true);
    expect(JSON.parse(await (error as HttpErrorResponse & { error: Blob }).error.text())).toEqual({
      error: 'step_up_required',
    });
  });

  it('recognises the refusal only as the API answers it', () => {
    const answer = (status: number, body: unknown) =>
      new HttpErrorResponse({ status, error: body, url: '/api/x' });

    expect(isDemoRefused(answer(403, { error: 'demo_refused' }))).toBe(true);
    expect(isDemoRefused(answer(400, { error: 'demo_refused' }))).toBe(false);
    expect(isDemoRefused(answer(403, { error: 'support_read_only' }))).toBe(false);
    expect(isDemoRefused(answer(403, 'demo_refused'))).toBe(false);
    expect(isDemoRefused(new Error('demo_refused'))).toBe(false);
  });
});
