// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthFacade } from './auth-facade';

async function resolveStatus(auth: AuthFacade): Promise<boolean> {
  if (auth.status() === 'unknown') {
    await auth.load();
  }
  return auth.isAuthenticated();
}

/** Signed-in pages: anyone else is sent to the login page. */
export const authGuard: CanActivateFn = async () => {
  const auth = inject(AuthFacade);
  const router = inject(Router);
  return (await resolveStatus(auth)) ? true : router.createUrlTree(['/login']);
};

/** The login page itself: a signed-in user has no business there and lands on the home page instead. */
export const anonymousGuard: CanActivateFn = async () => {
  const auth = inject(AuthFacade);
  const router = inject(Router);
  return (await resolveStatus(auth)) ? router.createUrlTree(['/']) : true;
};
