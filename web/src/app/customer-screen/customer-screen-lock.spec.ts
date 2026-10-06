// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router, type UrlTree } from '@angular/router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AuthFacade } from '../auth/auth-facade';
import { CustomerView } from '../shared/customer-view/customer-view';
import { customerScreenLock } from './customer-screen-lock';

describe('customerScreenLock', () => {
  const active = signal(false);
  const status = signal<'unknown' | 'authenticated'>('authenticated');
  const auth = { status, load: vi.fn() };

  const run = async (): Promise<boolean | UrlTree> =>
    (await TestBed.runInInjectionContext(() => customerScreenLock({} as never, {} as never))) as
      boolean | UrlTree;

  beforeEach(() => {
    active.set(false);
    status.set('authenticated');
    auth.load.mockReset().mockResolvedValue(null);
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        { provide: CustomerView, useValue: { active } },
        { provide: AuthFacade, useValue: auth },
      ],
    });
  });

  it('lets every screen through while the customer screen does not hold the sign-in', async () => {
    expect(await run()).toBe(true);
  });

  it('sends any other screen back to the customer screen while it holds the sign-in', async () => {
    active.set(true);

    const answer = await run();

    expect(answer).not.toBe(true);
    expect(TestBed.inject(Router).serializeUrl(answer as UrlTree)).toBe('/customer-screen');
  });

  it('reads the sign-in first in a new tab, so a held one lands on the screen', async () => {
    status.set('unknown');
    auth.load.mockImplementation(() => {
      active.set(true);
      return Promise.resolve(null);
    });

    const answer = await run();

    expect(auth.load).toHaveBeenCalledOnce();
    expect(TestBed.inject(Router).serializeUrl(answer as UrlTree)).toBe('/customer-screen');
  });
});
