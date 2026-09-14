// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import {
  type ActivatedRouteSnapshot,
  provideRouter,
  Router,
  type RouterStateSnapshot,
  UrlTree,
} from '@angular/router';
import { AuthFacade } from '../auth/auth-facade';
import { moduleGuard } from './module-guard';

describe('moduleGuard', () => {
  const auth = { hasModule: vi.fn() };

  beforeEach(() => {
    auth.hasModule.mockReset();
    TestBed.configureTestingModule({
      providers: [provideRouter([]), { provide: AuthFacade, useValue: auth }],
    });
  });

  const run = (module: string) =>
    TestBed.runInInjectionContext(() =>
      moduleGuard(module)({} as ActivatedRouteSnapshot, {} as RouterStateSnapshot),
    );

  it("lets the user into a module's pages while the company has it on", () => {
    auth.hasModule.mockReturnValue(true);

    expect(run('customers')).toBe(true);
    expect(auth.hasModule).toHaveBeenCalledWith('customers');
  });

  it('sends the user home while the company has the module off', () => {
    auth.hasModule.mockReturnValue(false);

    const result = run('customers');

    expect(result).toBeInstanceOf(UrlTree);
    expect(TestBed.inject(Router).serializeUrl(result as UrlTree)).toBe('/');
  });
});
