// SPDX-License-Identifier: AGPL-3.0-or-later

import { isDevMode } from '@angular/core';
import { Routes } from '@angular/router';
import { anonymousGuard, authGuard } from './auth/auth-guard';
import { CUSTOMERS_MODULE } from './customers/customers-nav';
import { DELIVERY_NOTES_MODULE } from './delivery-notes/delivery-notes-nav';
import { PRODUCTS_MODULE } from './products/products-nav';
import { moduleGuard } from './shell/module-guard';

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
        canActivate: [moduleGuard(CUSTOMERS_MODULE)],
        loadComponent: () => import('./customers/customers-page').then((m) => m.CustomersPage),
      },
      {
        // Before ':customerId', which would otherwise take "new" and "groups" for identifiers.
        path: 'customers/new',
        canActivate: [moduleGuard(CUSTOMERS_MODULE)],
        loadComponent: () => import('./customers/customer-page').then((m) => m.CustomerPage),
      },
      {
        path: 'customers/groups',
        canActivate: [moduleGuard(CUSTOMERS_MODULE)],
        loadComponent: () =>
          import('./customers/customer-groups-page').then((m) => m.CustomerGroupsPage),
      },
      {
        path: 'customers/:customerId',
        canActivate: [moduleGuard(CUSTOMERS_MODULE)],
        loadComponent: () => import('./customers/customer-page').then((m) => m.CustomerPage),
      },
      {
        path: 'products',
        canActivate: [moduleGuard(PRODUCTS_MODULE)],
        loadComponent: () => import('./products/products-page').then((m) => m.ProductsPage),
      },
      {
        // Before ':productId', which would otherwise take "new" and "categories" for identifiers.
        path: 'products/new',
        canActivate: [moduleGuard(PRODUCTS_MODULE)],
        loadComponent: () => import('./products/product-page').then((m) => m.ProductPage),
      },
      {
        path: 'products/categories',
        canActivate: [moduleGuard(PRODUCTS_MODULE)],
        loadComponent: () =>
          import('./products/product-categories-page').then((m) => m.ProductCategoriesPage),
      },
      {
        path: 'products/:productId',
        canActivate: [moduleGuard(PRODUCTS_MODULE)],
        loadComponent: () => import('./products/product-page').then((m) => m.ProductPage),
      },
      {
        path: 'delivery-notes',
        canActivate: [moduleGuard(DELIVERY_NOTES_MODULE)],
        loadComponent: () =>
          import('./delivery-notes/delivery-notes-page').then((m) => m.DeliveryNotesPage),
      },
      {
        // Before ':deliveryNoteId', which would otherwise take "new" for an identifier.
        path: 'delivery-notes/new',
        canActivate: [moduleGuard(DELIVERY_NOTES_MODULE)],
        loadComponent: () =>
          import('./delivery-notes/delivery-note-page').then((m) => m.DeliveryNotePage),
      },
      {
        path: 'delivery-notes/:deliveryNoteId',
        canActivate: [moduleGuard(DELIVERY_NOTES_MODULE)],
        loadComponent: () =>
          import('./delivery-notes/delivery-note-page').then((m) => m.DeliveryNotePage),
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
        path: 'company/modules',
        loadComponent: () => import('./company/modules-page').then((m) => m.ModulesPage),
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
