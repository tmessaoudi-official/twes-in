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
        path: 'customers',
        loadComponent: () => import('./customers/customers-page').then((m) => m.CustomersPage),
      },
      {
        // Before ':customerId', which would otherwise take "new" and "groups" for identifiers.
        path: 'customers/new',
        loadComponent: () => import('./customers/customer-page').then((m) => m.CustomerPage),
      },
      {
        path: 'customers/groups',
        loadComponent: () =>
          import('./customers/customer-groups-page').then((m) => m.CustomerGroupsPage),
      },
      {
        path: 'customers/:customerId',
        loadComponent: () => import('./customers/customer-page').then((m) => m.CustomerPage),
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
        path: 'settings',
        loadComponent: () => import('./settings/settings-page').then((m) => m.SettingsPage),
      },
      {
        path: 'company/profile',
        loadComponent: () =>
          import('./company/company-profile-page').then((m) => m.CompanyProfilePage),
      },
      {
        path: 'company/establishments',
        loadComponent: () =>
          import('./company/establishments-page').then((m) => m.EstablishmentsPage),
      },
      {
        path: 'company/numbering',
        loadComponent: () => import('./company/numbering-page').then((m) => m.NumberingPage),
      },
      {
        path: 'company/custom-fields',
        loadComponent: () => import('./company/custom-fields-page').then((m) => m.CustomFieldsPage),
      },
      {
        // The G2b design checkpoint's fixture screens. canMatch keeps them out of a production build's router
        // entirely; the nav entry is devOnly for the same reason.
        path: 'design',
        canMatch: [() => isDevMode()],
        loadComponent: () => import('./design/design-page').then((m) => m.DesignPage),
        children: [
          { path: '', pathMatch: 'full', redirectTo: 'invoice' },
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
