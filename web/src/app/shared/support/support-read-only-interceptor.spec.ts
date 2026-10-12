// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Feedback } from '../feedback/feedback';
import { supportReadOnlyInterceptor } from './support-read-only-interceptor';

describe('supportReadOnlyInterceptor', () => {
  const feedback = { failure: vi.fn() };

  beforeEach(() => {
    feedback.failure.mockReset();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([supportReadOnlyInterceptor])),
        provideHttpClientTesting(),
        { provide: Feedback, useValue: feedback },
      ],
    });
  });

  const http = () => TestBed.inject(HttpClient);
  const backend = () => TestBed.inject(HttpTestingController);

  it('says in words that support only reads, and still hands the refusal to whoever asked', () => {
    const failed = vi.fn();
    http().post('/api/companies/c1/customers', {}).subscribe({ error: failed });

    backend()
      .expectOne('/api/companies/c1/customers')
      .flush({ error: 'support_read_only' }, { status: 403, statusText: 'Forbidden' });

    expect(feedback.failure).toHaveBeenCalledExactlyOnceWith('support_access.read_only');
    expect(failed).toHaveBeenCalledOnce();
  });

  it('leaves every other refusal to whoever asked', () => {
    http()
      .post('/api/companies/c1/customers', {})
      .subscribe({ error: () => undefined });
    backend()
      .expectOne('/api/companies/c1/customers')
      .flush({ error: 'subscription_read_only' }, { status: 403, statusText: 'Forbidden' });
    http()
      .get('/api/companies/c1/customers')
      .subscribe({ error: () => undefined });
    backend()
      .expectOne('/api/companies/c1/customers')
      .flush({ error: 'support_read_only' }, { status: 404, statusText: 'Not Found' });

    expect(feedback.failure).not.toHaveBeenCalled();
  });
});
