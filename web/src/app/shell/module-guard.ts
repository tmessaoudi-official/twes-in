// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject } from '@angular/core';
import { type CanActivateFn, Router } from '@angular/router';
import { AuthFacade } from '../auth/auth-facade';

/**
 * A module's pages open only while the working company has the module on; otherwise the user is sent home. A
 * courtesy like the navigation: the API answers 404 for the module's resources anyway (docs/SPEC.md § 3 Modules).
 */
export function moduleGuard(module: string): CanActivateFn {
  return () => inject(AuthFacade).hasModule(module) || inject(Router).createUrlTree(['/']);
}
