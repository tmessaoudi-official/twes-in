// SPDX-License-Identifier: AGPL-3.0-or-later

import { isDevMode } from '@angular/core';
import { Routes } from '@angular/router';
import { anonymousGuard, authGuard } from './auth/auth-guard';

export const routes: Routes = [
  {
    path: 'login',
    canActivate: [anonymousGuard],
    loadComponent: () => import('./auth/login-page').then((m) => m.LoginPage),
  },
  {
    // Opened from a mail client, with no session: deliberately outside both guards and outside the shell.
    path: 'invitations/:token',
    loadComponent: () =>
      import('./invitation/accept-invitation-page').then((m) => m.AcceptInvitationPage),
  },
  {
    // Every signed-in page is a child of the shell, which carries the navigation and the account menu.
    path: '',
    canActivate: [authGuard],
    loadComponent: () => import('./shell/app-shell').then((m) => m.AppShell),
    children: [
      {
        path: '',
        pathMatch: 'full',
        loadComponent: () => import('./hello/hello-page').then((m) => m.HelloPage),
      },
      {
        path: 'members',
        loadComponent: () => import('./company/members-page').then((m) => m.MembersPage),
      },
      {
        path: 'fiscal/taxes',
        loadComponent: () => import('./fiscal/fiscal-taxes-page').then((m) => m.FiscalTaxesPage),
      },
      {
        path: 'fiscal/units',
        loadComponent: () => import('./fiscal/fiscal-units-page').then((m) => m.FiscalUnitsPage),
      },
      {
        // The G2b design checkpoint's fixture screens. canMatch keeps them out of a production build's router
        // entirely; the nav entry is devOnly for the same reason.
        path: 'design',
        canMatch: [() => isDevMode()],
        loadComponent: () => import('./design/design-page').then((m) => m.DesignPage),
        children: [
          { path: '', pathMatch: 'full', redirectTo: 'customers' },
          {
            path: 'customers',
            loadComponent: () =>
              import('./design/design-customers-page').then((m) => m.DesignCustomersPage),
          },
          {
            path: 'customers/new',
            loadComponent: () =>
              import('./design/design-customer-form-page').then((m) => m.DesignCustomerFormPage),
          },
          {
            path: 'invoice',
            loadComponent: () =>
              import('./design/design-invoice-page').then((m) => m.DesignInvoicePage),
          },
        ],
      },
    ],
  },
  { path: '**', redirectTo: '' },
];
