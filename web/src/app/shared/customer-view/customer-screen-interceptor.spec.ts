// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CustomerScreenHold } from './customer-screen-hold';
import { customerScreenInterceptor } from './customer-screen-interceptor';

describe('customerScreenInterceptor', () => {
  const hold = { hold: vi.fn(), release: vi.fn(), reread: vi.fn() };
  const router = { navigateByUrl: vi.fn() };

  beforeEach(() => {
    hold.reread.mockReset().mockResolvedValue(undefined);
    router.navigateByUrl.mockReset().mockResolvedValue(true);
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([customerScreenInterceptor])),
        provideHttpClientTesting(),
        { provide: CustomerScreenHold, useValue: hold },
        { provide: Router, useValue: router },
      ],
    });
  });

  const http = () => TestBed.inject(HttpClient);
  const backend = () => TestBed.inject(HttpTestingController);

  it('sends a tab the screen holds back to it, once it read the sign-in again', async () => {
    const failed = vi.fn();
    http().get('/api/companies/c1/customers').subscribe({ error: failed });

    backend()
      .expectOne('/api/companies/c1/customers')
      .flush({ error: 'customer_screen_locked' }, { status: 403, statusText: 'Forbidden' });
    await Promise.resolve();
    await Promise.resolve();

    expect(failed).toHaveBeenCalledOnce();
    expect(hold.reread).toHaveBeenCalledOnce();
    expect(router.navigateByUrl).toHaveBeenCalledWith('/customer-screen');
  });

  it('leaves every other refusal to whoever asked', async () => {
    http()
      .get('/api/companies/c1/customers')
      .subscribe({ error: () => undefined });
    backend()
      .expectOne('/api/companies/c1/customers')
      .flush({ error: 'forbidden' }, { status: 403, statusText: 'Forbidden' });
    http()
      .delete('/api/auth/customer-screen')
      .subscribe({ error: () => undefined });
    backend()
      .expectOne('/api/auth/customer-screen')
      .flush({ error: 'step_up_required' }, { status: 403, statusText: 'Forbidden' });
    await Promise.resolve();

    expect(hold.reread).not.toHaveBeenCalled();
    expect(router.navigateByUrl).not.toHaveBeenCalled();
  });
});
