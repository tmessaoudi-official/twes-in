// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router, type UrlTree } from '@angular/router';
import { beforeEach, describe, expect, it } from 'vitest';
import { CustomerView } from '../shared/customer-view/customer-view';
import { customerScreenLock } from './customer-screen-lock';

describe('customerScreenLock', () => {
  const active = signal(false);

  const run = (): boolean | UrlTree =>
    TestBed.runInInjectionContext(() => customerScreenLock({} as never, {} as never)) as
      boolean | UrlTree;

  beforeEach(() => {
    active.set(false);
    TestBed.configureTestingModule({
      providers: [provideRouter([]), { provide: CustomerView, useValue: { active } }],
    });
  });

  it('lets every screen through while the customer screen is not open', () => {
    expect(run()).toBe(true);
  });

  it('sends any other screen back to the customer screen while it is open', () => {
    active.set(true);

    const answer = run();

    expect(answer).not.toBe(true);
    expect(TestBed.inject(Router).serializeUrl(answer as UrlTree)).toBe('/customer-screen');
  });
});
