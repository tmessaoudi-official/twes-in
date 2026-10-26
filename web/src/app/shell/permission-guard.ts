// SPDX-License-Identifier: AGPL-3.0-or-later

import { effect, inject, Injectable, untracked } from '@angular/core';
import {
  type ActivatedRouteSnapshot,
  type CanActivateFn,
  Router,
  type UrlTree,
} from '@angular/router';
import { AuthFacade } from '../auth/auth-facade';
import type { Gated } from './nav-manifest';

/**
 * A page a manifest declares, with what it needs: a navigation entry, a palette command or a page tab. The route guard
 * reads the declaration itself, never a copy of its permission, so the menu and the guard cannot disagree.
 */
export interface GatedPage extends Gated {
  readonly route: string;
  readonly labelKey: string;
}

/** Why a page is not opened here: the module is off, the role does not grant it, or the subscription narrows it. */
export type Refusal = 'module' | 'role' | 'subscription';

/** Where a refused page sends the person: a page saying which page and why, with a way home. */
export const NOT_ALLOWED_ROUTE = '/not-allowed';

/** Every guard made, with the declaration it reads: the watch below finds the open page's ones here. */
const GUARDS = new Map<CanActivateFn, GatedPage>();
/** The same declarations by their route, which the refusal page reads from its address. */
const PAGES = new Map<string, GatedPage>();

/**
 * Opens a route only to whoever the menu shows its declaration to (hunt P-2): without it, a clerk switching company on
 * « Fournisseurs », or following a link, met a list that failed to load and offered « Exporter ». The declaration is the
 * one `declarations` names for `route`, which must exist and need something, or the app fails at start.
 */
export function permissionGuard(declarations: readonly GatedPage[], route: string): CanActivateFn {
  const page = declarations.find((declaration) => declaration.route === route);
  if (page === undefined || (page.permission === undefined && page.role === undefined)) {
    throw new Error(`permissionGuard: no declaration of ${route} needing a permission or a role`);
  }
  const guard: CanActivateFn = (_route, state) => inject(RouteGates).admit(page, state.url);
  GUARDS.set(guard, page);
  PAGES.set(page.route, page);
  return guard;
}

/** The declaration a guard was made from, by its route; null for a route no guard reads. */
export function gatedPage(route: string): GatedPage | null {
  return PAGES.get(route) ?? null;
}

/**
 * Decides a guarded page, and keeps deciding while it is open: switching company rebuilds the screen without a
 * navigation, so no guard runs, and a role lowered while the page is open changes nothing either. When the session
 * changes, the open page's declarations are read again and a page no longer allowed is left for the refusal page.
 */
@Injectable({ providedIn: 'root' })
export class RouteGates {
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);
  constructor() {
    effect(() => {
      // Signed out, or between two reads of the session: the auth guards decide that, never this.
      if (this.auth.me() === null) return;
      untracked(() => this.recheck());
    });
  }

  /** Null when the page opens; otherwise why not, judged as the menu judges its entries. */
  refusal(page: Gated): Refusal | null {
    const me = this.auth.me();
    if (page.module !== undefined && !this.auth.hasModule(page.module)) return 'module';
    if (page.role !== undefined && page.role !== me?.company?.role) return 'role';
    if (page.permission === undefined || this.auth.hasPermission(page.permission)) return null;
    return this.auth.grantedByRole(page.permission) ? 'subscription' : 'role';
  }

  admit(page: GatedPage, url: string): true | UrlTree {
    const refusal = this.refusal(page);
    if (refusal === null) return true;
    if (refusal === 'module') return this.router.createUrlTree(['/']);
    return this.router.createUrlTree([NOT_ALLOWED_ROUTE], {
      queryParams: { page: page.route, from: url },
    });
  }

  private recheck(): void {
    if (this.router.getCurrentNavigation() !== null) return;
    const url = this.router.url;
    for (const page of this.openPages(this.router.routerState.snapshot.root)) {
      const admitted = this.admit(page, url);
      if (admitted !== true) {
        void this.router.navigateByUrl(admitted, { replaceUrl: true });
        return;
      }
    }
  }

  /** The declarations the guards of the open route read, from the outermost route in. */
  private openPages(snapshot: ActivatedRouteSnapshot): GatedPage[] {
    const own = (snapshot.routeConfig?.canActivate ?? [])
      .map((guard) => GUARDS.get(guard as CanActivateFn))
      .filter((page): page is GatedPage => page !== undefined);
    return [...own, ...snapshot.children.flatMap((child) => this.openPages(child))];
  }
}
