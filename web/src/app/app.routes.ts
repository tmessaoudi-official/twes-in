// SPDX-License-Identifier: AGPL-3.0-or-later

import { Routes } from '@angular/router';
import {
  anonymousGuard,
  authGuard,
  awaitingApprovalGuard,
  lockedSubscriptionGuard,
  operatorGuard,
  twoFactorGuard,
} from './auth/auth-guard';
import { CUSTOMERS_MODULE } from './customers/customers-nav';
import { DELIVERY_NOTES_MODULE } from './delivery-notes/delivery-notes-nav';
import { INVOICES_MODULE } from './invoices/invoices-nav';
import { EXPENSES_MODULE } from './expenses/expenses-nav';
import { INVENTORY_MODULE } from './inventory/inventory-nav';
import { PRODUCTS_MODULE } from './products/products-nav';
import { VENDORS_MODULE } from './vendors/vendors-nav';
import { guardUnsaved } from './shared/form/unsaved-changes';
import { moduleGuard } from './shell/module-guard';

export const routes: Routes = [
  {
    path: 'login',
    canActivate: [anonymousGuard],
    loadComponent: () => import('./auth/login-page').then((m) => m.LoginPage),
  },
  {
    // Setting up a second factor. Outside the shell: an account a company requires to enrol is refused by every
    // other endpoint until it has, so the shell could not load.
    path: 'two-factor',
    canActivate: [twoFactorGuard],
    loadComponent: () => import('./auth/two-factor-page').then((m) => m.TwoFactorPage),
  },
  {
    // Opened from a mail client, with no session: deliberately outside both guards and outside the shell.
    path: 'invitations/:token',
    loadComponent: () =>
      import('./invitation/accept-invitation-page').then((m) => m.AcceptInvitationPage),
  },
  {
    // A phone lent to a computer as a scanner (docs/SPEC.md § 7, 2026-09-23 09:45, slice 4): no sign-in by design,
    // the link it claims is its only right. Outside both guards and outside the shell, like an invitation.
    path: 'pair',
    loadComponent: () => import('./pairing/phone-scanner-page').then((m) => m.PhoneScannerPage),
  },
  {
    // The customer display (docs/SPEC.md § 7, 2026-09-23 slice 6): a second window of a signed-in browser, turned
    // towards the customer. Outside the shell, so it shows no menu and no scan card opens on it.
    path: 'customer-display',
    canActivate: [authGuard],
    loadComponent: () =>
      import('./customer-display/customer-display-page').then((m) => m.CustomerDisplayPage),
  },
  {
    // Sheets of location and product labels (docs/SPEC.md § 7, 2026-09-23 slice 8): outside the shell, so the paper carries
    // nothing but the labels. The module guard sits on the child, as in the shell, so the session is read first.
    path: 'print',
    canActivate: [authGuard],
    children: [
      {
        path: 'location-labels',
        canActivate: [moduleGuard(INVENTORY_MODULE)],
        loadComponent: () =>
          import('./inventory/location-labels-page').then((m) => m.LocationLabelsPage),
      },
      {
        path: 'product-labels/:productId',
        canActivate: [moduleGuard(PRODUCTS_MODULE)],
        loadComponent: () =>
          import('./products/product-labels-page').then((m) => m.ProductLabelsPage),
      },
    ],
  },
  {
    // Asking for a signup link is for somebody not signed in.
    path: 'signup',
    canActivate: [anonymousGuard],
    loadComponent: () => import('./signup/signup-page').then((m) => m.SignupPage),
  },
  {
    // The far end of a signup link, opened from a mail client with no session: outside both guards, like an invitation.
    path: 'signup/:token',
    loadComponent: () => import('./signup/finish-signup-page').then((m) => m.FinishSignupPage),
  },
  {
    // A company its subscription locked: outside the shell like the page below, because the API refuses its members
    // everything but reading the subscription and declaring a payment — which is what this page is for.
    path: 'subscription',
    canActivate: [lockedSubscriptionGuard],
    loadComponent: () =>
      import('./licensing/locked-subscription-page').then((m) => m.LockedSubscriptionPage),
  },
  {
    // A member of a company that is not active, pending approval or suspended: outside the shell, which could not load.
    path: 'awaiting-approval',
    canActivate: [awaitingApprovalGuard],
    loadComponent: () =>
      import('./auth/awaiting-approval-page').then((m) => m.AwaitingApprovalPage),
  },
  {
    // Every signed-in page is a child of the shell, which carries the navigation and the account menu.
    path: '',
    canActivate: [authGuard],
    loadComponent: () => import('./shell/app-shell').then((m) => m.AppShell),
    // Guarded as one list, not one route at a time: a record page added below would otherwise be able to be
    // left with unsaved changes, and nothing would say so (row 45).
    children: guardUnsaved([
      {
        path: '',
        pathMatch: 'full',
        loadComponent: () => import('./hello/hello-page').then((m) => m.HelloPage),
      },
      {
        path: 'watch',
        loadComponent: () => import('./watch/watch-page').then((m) => m.WatchPage),
      },
      {
        // « Mon compte »: the person's own account, apart from any company (docs/SPEC.md § 7, 2026-09-25 17:22).
        path: 'account',
        loadComponent: () => import('./account/account-page').then((m) => m.AccountPage),
      },
      {
        // What the vision holds and is not built yet (docs/SPEC.md § 7, 2026-09-25 17:22).
        path: 'coming/:key',
        loadComponent: () => import('./shell/coming-page').then((m) => m.ComingPage),
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
        path: 'products/price-check',
        canActivate: [moduleGuard(PRODUCTS_MODULE)],
        loadComponent: () => import('./products/price-check-page').then((m) => m.PriceCheckPage),
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
        path: 'invoices',
        canActivate: [moduleGuard(INVOICES_MODULE)],
        loadComponent: () => import('./invoices/invoices-page').then((m) => m.InvoicesPage),
      },
      {
        // Before ':invoiceId', which would otherwise take "new" for an identifier.
        path: 'invoices/new',
        canActivate: [moduleGuard(INVOICES_MODULE)],
        loadComponent: () => import('./invoices/invoice-page').then((m) => m.InvoicePage),
      },
      {
        path: 'invoices/:invoiceId',
        canActivate: [moduleGuard(INVOICES_MODULE)],
        loadComponent: () => import('./invoices/invoice-page').then((m) => m.InvoicePage),
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
        path: 'stock',
        canActivate: [moduleGuard(INVENTORY_MODULE)],
        loadComponent: () => import('./inventory/stock-page').then((m) => m.StockPage),
      },
      {
        // Count mode (docs/SPEC.md § 7, 2026-09-23 slice 8): a scanner walking the shelves.
        path: 'stock/count',
        canActivate: [moduleGuard(INVENTORY_MODULE)],
        loadComponent: () => import('./inventory/stock-count-page').then((m) => m.StockCountPage),
      },
      {
        // A location label's QR code (slice 8): count mode, at that location.
        path: 'stock/locations/:location',
        canActivate: [moduleGuard(INVENTORY_MODULE)],
        loadComponent: () => import('./inventory/stock-count-page').then((m) => m.StockCountPage),
      },
      {
        path: 'stock/movements',
        canActivate: [moduleGuard(INVENTORY_MODULE)],
        loadComponent: () =>
          import('./inventory/stock-movements-page').then((m) => m.StockMovementsPage),
      },
      {
        path: 'stock/locations',
        canActivate: [moduleGuard(INVENTORY_MODULE)],
        loadComponent: () =>
          import('./inventory/stock-locations-page').then((m) => m.StockLocationsPage),
      },
      {
        path: 'stock/plan',
        canActivate: [moduleGuard(INVENTORY_MODULE)],
        loadComponent: () => import('./inventory/stock-map-page').then((m) => m.StockMapPage),
      },
      {
        // One screen for every subject a module declares as importable: the API already answers 404 for a subject
        // this company cannot import, or may not write, so no static module guard could say it here.
        path: 'imports/:subject',
        loadComponent: () => import('./import/import-page').then((m) => m.ImportPage),
      },
      {
        path: 'vendors',
        canActivate: [moduleGuard(VENDORS_MODULE)],
        loadComponent: () => import('./vendors/vendors-page').then((m) => m.VendorsPage),
      },
      {
        path: 'vendors/new',
        canActivate: [moduleGuard(VENDORS_MODULE)],
        loadComponent: () => import('./vendors/vendor-page').then((m) => m.VendorPage),
      },
      {
        path: 'vendors/:vendorId',
        canActivate: [moduleGuard(VENDORS_MODULE)],
        loadComponent: () => import('./vendors/vendor-page').then((m) => m.VendorPage),
      },
      {
        path: 'expenses',
        canActivate: [moduleGuard(EXPENSES_MODULE)],
        loadComponent: () => import('./expenses/expenses-page').then((m) => m.ExpensesPage),
      },
      {
        path: 'expenses/categories',
        canActivate: [moduleGuard(EXPENSES_MODULE)],
        loadComponent: () =>
          import('./expenses/expense-categories-page').then((m) => m.ExpenseCategoriesPage),
      },
      {
        path: 'expenses/new',
        canActivate: [moduleGuard(EXPENSES_MODULE)],
        loadComponent: () => import('./expenses/expense-page').then((m) => m.ExpensePage),
      },
      {
        path: 'expenses/:expenseId',
        canActivate: [moduleGuard(EXPENSES_MODULE)],
        loadComponent: () => import('./expenses/expense-page').then((m) => m.ExpensePage),
      },
      {
        // The platform's operators run signup and decide on the companies waiting for approval here.
        path: 'platform',
        canActivate: [operatorGuard],
        loadComponent: () => import('./platform/platform-page').then((m) => m.PlatformPage),
      },
      {
        // The company settings, reached from the gear: a layout route with no path of its own, so the grouped
        // settings navigation sits beside each page and every page keeps its address.
        path: '',
        loadComponent: () => import('./shell/settings-area').then((m) => m.SettingsArea),
        children: [
          {
            // The list of settings on its own, which the gear opens on a phone.
            path: 'company',
            pathMatch: 'full',
            loadComponent: () => import('./shell/settings-index').then((m) => m.SettingsIndex),
          },
          {
            path: 'members',
            loadComponent: () => import('./company/members-page').then((m) => m.MembersPage),
          },
          {
            // A settings entry not built yet opens beside the settings list (docs/SPEC.md § 7, 2026-09-25 17:22).
            path: 'company/coming/:key',
            loadComponent: () => import('./shell/coming-page').then((m) => m.ComingPage),
          },
          {
            path: 'company/roles',
            loadComponent: () => import('./company/roles-page').then((m) => m.RolesPage),
          },
          {
            path: 'fiscal/taxes',
            loadComponent: () =>
              import('./fiscal/fiscal-taxes-page').then((m) => m.FiscalTaxesPage),
          },
          {
            path: 'fiscal/units',
            loadComponent: () =>
              import('./fiscal/fiscal-units-page').then((m) => m.FiscalUnitsPage),
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
            path: 'company/security',
            loadComponent: () =>
              import('./company/company-security-page').then((m) => m.CompanySecurityPage),
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
            loadComponent: () =>
              import('./company/custom-fields-page').then((m) => m.CustomFieldsPage),
          },
          {
            path: 'company/modules',
            loadComponent: () => import('./company/modules-page').then((m) => m.ModulesPage),
          },
          {
            // Reachable whatever the subscription says: a locked company reaches nothing else, and this is the
            // way out of it (docs/SPEC.md § 7, 2026-09-17).
            path: 'company/subscription',
            loadComponent: () =>
              import('./licensing/subscription-page').then((m) => m.SubscriptionPage),
          },
        ],
      },
    ]),
  },
  {
    // Open to anyone, signed in or not, outside the shell (docs/SPEC.md § 7, row 147).
    path: 'legal/:slug',
    loadComponent: () => import('./legal/legal-page').then((m) => m.LegalPage),
  },
  { path: '**', redirectTo: '' },
];
