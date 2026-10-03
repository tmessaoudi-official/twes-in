// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject } from '@angular/core';
import { type CanActivateFn, Router } from '@angular/router';
import { CustomerView } from '../shared/customer-view/customer-view';

/**
 * While the customer screen is open, nothing else of the signed-in app is reachable (docs/SPEC.md § 7, 2026-10-03
 * 08:20): a typed address, a bookmark, the back button and a reload all land on it again. Put on every signed-in route
 * but the screen itself; leaving takes the password or a passkey (`CustomerView.leave`).
 */
export const customerScreenLock: CanActivateFn = () => {
  const view = inject(CustomerView);
  return view.active() ? inject(Router).createUrlTree(['/customer-screen']) : true;
};
