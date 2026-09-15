// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import {
  type ActivatedRouteSnapshot,
  type CanActivateFn,
  provideRouter,
  Router,
  type RouterStateSnapshot,
  UrlTree,
} from '@angular/router';
import { AuthFacade } from './auth-facade';
import { anonymousGuard, authGuard, twoFactorGuard } from './auth-guard';

function facade(status: 'anonymous' | 'authenticated', needsEnrolment = false) {
  return {
    status: () => status,
    isAuthenticated: () => status === 'authenticated',
    needsEnrolment: () => needsEnrolment,
    load: vi.fn(),
  };
}

/** Runs a guard against a fake facade and answers true, or the address it redirects to. */
async function run(guard: CanActivateFn, fake: ReturnType<typeof facade>): Promise<true | string> {
  // Each call stands alone, so one test can compare several accounts.
  TestBed.resetTestingModule();
  TestBed.configureTestingModule({
    providers: [provideRouter([]), { provide: AuthFacade, useValue: fake }],
  });
  const result = await TestBed.runInInjectionContext(() =>
    guard({} as ActivatedRouteSnapshot, {} as RouterStateSnapshot),
  );
  return result instanceof UrlTree ? TestBed.inject(Router).serializeUrl(result) : (result as true);
}

describe('auth guards', () => {
  it('sends anybody not signed in from a signed-in page to the login page', async () => {
    expect(await run(authGuard, facade('anonymous'))).toBe('/login');
  });

  it('sends an account that must enrol to the two-step verification page before anything else', async () => {
    expect(await run(authGuard, facade('authenticated', true))).toBe('/two-factor');
    expect(await run(authGuard, facade('authenticated', false))).toBe(true);
  });

  it('opens the two-step verification page to any signed-in account, enrolled or not', async () => {
    expect(await run(twoFactorGuard, facade('authenticated', true))).toBe(true);
    expect(await run(twoFactorGuard, facade('authenticated', false))).toBe(true);
    expect(await run(twoFactorGuard, facade('anonymous'))).toBe('/login');
  });

  it('keeps a signed-in account off the login page', async () => {
    expect(await run(anonymousGuard, facade('authenticated'))).toBe('/');
    expect(await run(anonymousGuard, facade('anonymous'))).toBe(true);
  });
});
