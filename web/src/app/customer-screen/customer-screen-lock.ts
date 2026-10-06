// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject } from '@angular/core';
import { type CanActivateFn, Router } from '@angular/router';
import { AuthFacade } from '../auth/auth-facade';
import { CustomerView } from '../shared/customer-view/customer-view';

/**
 * While the customer screen holds the sign-in, nothing else of the signed-in app is reachable (docs/SPEC.md § 7,
 * 2026-10-06 19:44): a typed address, a bookmark, the back button, a reload and a new tab all land on it again. The API
 * refuses the rest anyway; this only spares the person a screen of refusals. Put on every signed-in route but the
 * screen itself; leaving takes the password or a passkey (`CustomerView.leave`).
 */
export const customerScreenLock: CanActivateFn = async () => {
  const auth = inject(AuthFacade);
  const view = inject(CustomerView);
  const router = inject(Router);
  // Guards run side by side: a new tab must know the sign-in before it can tell whether the screen holds it.
  if (auth.status() === 'unknown') await auth.load();
  return view.active() ? router.createUrlTree(['/customer-screen']) : true;
};
