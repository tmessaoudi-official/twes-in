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

/**
 * Signed-in pages: anyone else is sent to the login page, and an account a company requires to enrol is sent
 * to set up its second factor first, because the API refuses it everything else until then.
 */
export const authGuard: CanActivateFn = async () => {
  const auth = inject(AuthFacade);
  const router = inject(Router);
  if (!(await resolveStatus(auth))) {
    return router.createUrlTree(['/login']);
  }
  if (auth.needsEnrolment()) {
    return router.createUrlTree(['/two-factor']);
  }
  return auth.companyClosed() ? router.createUrlTree(['/awaiting-approval']) : true;
};

/** The page that says a company is not active yet: only for a signed-in member of one, and nobody else. */
export const awaitingApprovalGuard: CanActivateFn = async () => {
  const auth = inject(AuthFacade);
  const router = inject(Router);
  if (!(await resolveStatus(auth))) {
    return router.createUrlTree(['/login']);
  }
  return auth.companyClosed() ? true : router.createUrlTree(['/']);
};

/** The platform page: its operators only; anybody else signed in lands on the home page. */
export const operatorGuard: CanActivateFn = async () => {
  const auth = inject(AuthFacade);
  const router = inject(Router);
  if (!(await resolveStatus(auth))) {
    return router.createUrlTree(['/login']);
  }
  return auth.isPlatformOperator() ? true : router.createUrlTree(['/']);
};

/** The two-step verification page: any signed-in account, including one the enrolment requirement holds back. */
export const twoFactorGuard: CanActivateFn = async () => {
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
