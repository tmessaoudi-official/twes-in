// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Feedback } from '../feedback/feedback';
import { demoRefusedInterceptor } from './demo-refused-interceptor';

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
});
