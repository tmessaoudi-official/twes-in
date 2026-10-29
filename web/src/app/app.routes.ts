// SPDX-License-Identifier: AGPL-3.0-or-later

import { Routes } from '@angular/router';
import {
  anonymousGuard,
  authGuard,
  awaitingApprovalGuard,
  closedCompanyMatch,
  lockedSubscriptionGuard,
  operatorGuard,
  twoFactorGuard,
} from './auth/auth-guard';
import { CUSTOMERS_COMMANDS, CUSTOMERS_MODULE, CUSTOMERS_NAV } from './customers/customers-nav';
import {
  DELIVERY_NOTES_COMMANDS,
  DELIVERY_NOTES_MODULE,
  DELIVERY_NOTES_NAV,
} from './delivery-notes/delivery-notes-nav';
import { QUOTES_COMMANDS, QUOTES_MODULE, QUOTES_NAV } from './quotes/quotes-nav';
import { INVOICES_COMMANDS, INVOICES_MODULE, INVOICES_NAV } from './invoices/invoices-nav';
import {
  EXPENSES_COMMANDS,
  EXPENSES_MODULE,
  EXPENSES_NAV,
  RECURRING_EXPENSES_MODULE,
} from './expenses/expenses-nav';
import {
  ACCOUNTING_EXPORT_MODULE,
  ACCOUNTING_EXPORT_NAV,
} from './accounting-export/accounting-export-nav';
import { RECURRING_MODULE } from './recurring/recurring-nav';
import { INVENTORY_MODULE, INVENTORY_NAV, INVENTORY_TABS } from './inventory/inventory-nav';
import { PRICE_LISTS_MODULE, PRICE_LISTS_NAV } from './price-lists/price-lists-nav';
import { PRODUCTS_COMMANDS, PRODUCTS_MODULE, PRODUCTS_NAV } from './products/products-nav';
import { PURCHASES_COMMANDS, PURCHASES_MODULE, PURCHASES_NAV } from './purchases/purchases-nav';
import { REPORTS_MODULE, REPORTS_NAV } from './reports/reports-nav';
import { VENDORS_COMMANDS, VENDORS_MODULE, VENDORS_NAV } from './vendors/vendors-nav';
import { guardUnsaved } from './shared/form/unsaved-changes';
import { customerScreenLock } from './customer-screen/customer-screen-lock';
import { moduleGuard } from './shell/module-guard';
import { MANAGE_NAV, SETTINGS_NAV } from './shell/settings-nav';
import { permissionGuard } from './shell/permission-guard';
import { FORM_REFUSAL_TEXTS } from './shared/form/form-refusal-texts';
import { textsOpened, textsPart } from './shared/i18n/texts-part';
import { MFA_LOCKOUT_TEXTS } from './auth/mfa-lockout-texts';
import { MEMBERS_TEXTS } from './company/members-texts';
import { ROLES_TEXTS } from './company/roles-texts';
import { CHECK_TEXTS } from './shared/check/check-texts';
import { PURCHASE_HISTORY_TEXTS } from './shared/i18n/purchase-history-texts';
import { WATCH_TEXTS } from './watch/watch-texts';
import { STOCK_COUNT_TEXTS } from './inventory/stock-count-texts';
import { RECORDS_TEXTS } from './shared/records/records-texts';
import { DELIVERIES_TEXTS } from './shared/documents/deliveries-texts';
import { SCHEDULE_TEXTS } from './shared/schedule/schedule-texts';
import { ACTIVITY_TEXTS } from './activity/activity-texts';

export const routes: Routes = [
  {
    path: 'login',
    title: 'titles.sign_in',
    canActivate: [anonymousGuard],
    loadComponent: () => import('./auth/login-page').then((m) => m.LoginPage),
  },
  {
    // Setting up a second factor. Outside the shell: an account a company requires to enrol is refused by every
    // other endpoint until it has, so the shell could not load.
    path: 'two-factor',
    title: 'titles.two_factor',
    ...textsPart('two-factor', twoFactorGuard, textsOpened(MFA_LOCKOUT_TEXTS)),
    loadComponent: () => import('./auth/two-factor-page').then((m) => m.TwoFactorPage),
  },
  {
    // Opened from a mail client, with no session: deliberately outside both guards and outside the shell.
    path: 'invitations/:token',
    title: 'titles.invitation',
    ...textsPart('invitation'),
    loadComponent: () =>
      import('./invitation/accept-invitation-page').then((m) => m.AcceptInvitationPage),
  },
  {
    // A phone lent to a computer as a scanner (docs/SPEC.md § 7, 2026-09-23 09:45, slice 4): no sign-in by design,
    // the link it claims is its only right. Outside both guards and outside the shell, like an invitation.
    path: 'pair',
    title: 'titles.phone_scanner',
    ...textsPart('phone-scanner'),
    loadComponent: () => import('./pairing/phone-scanner-page').then((m) => m.PhoneScannerPage),
  },
  {
    // The customer display (docs/SPEC.md § 7, 2026-09-23 slice 6): a second window of a signed-in browser, turned
    // towards the customer. Outside the shell, so it shows no menu and no scan card opens on it.
    path: 'customer-display',
    title: 'customer_display.title',
    canActivate: [authGuard, customerScreenLock],
    loadComponent: () =>
      import('./customer-display/customer-display-page').then((m) => m.CustomerDisplayPage),
  },
  {
    // Sheets of location and product labels (docs/SPEC.md § 7, 2026-09-23 slice 8): outside the shell, so the paper carries
    // nothing but the labels. The module guard sits on the child, as in the shell, so the session is read first.
    path: 'print',
    canActivate: [authGuard, customerScreenLock],
    children: [
      {
        path: 'location-labels',
        title: 'titles.location_labels',
        canActivate: [moduleGuard(INVENTORY_MODULE), permissionGuard(INVENTORY_NAV, '/stock')],
        loadComponent: () =>
          import('./inventory/location-labels-page').then((m) => m.LocationLabelsPage),
      },
      {
        path: 'product-labels',
        title: 'titles.product_labels',
        canActivate: [moduleGuard(PRODUCTS_MODULE), permissionGuard(PRODUCTS_NAV, '/products')],
        loadChildren: () =>
          import('./products/product-labels.routes').then((m) => m.PRODUCT_LABELS_ROUTES),
      },
    ],
  },
  {
    // Asking for a signup link is for somebody not signed in.
    path: 'signup',
    title: 'titles.signup',
    canActivate: [anonymousGuard],
    loadComponent: () => import('./signup/signup-page').then((m) => m.SignupPage),
  },
  {
    // The far end of a signup link, opened from a mail client with no session: outside both guards, like an invitation.
    path: 'signup/:token',
    title: 'titles.finish_signup',
    loadComponent: () => import('./signup/finish-signup-page').then((m) => m.FinishSignupPage),
  },
  {
    // Asking for a reset link is for somebody not signed in.
    path: 'forgot-password',
    title: 'titles.forgot_password',
    canActivate: [anonymousGuard],
    loadComponent: () =>
      import('./password-reset/forgot-password-page').then((m) => m.ForgotPasswordPage),
  },
  {
    // The far end of a reset link, opened from a mail client with no session: outside both guards, like a signup link.
    path: 'reset-password/:token',
    title: 'titles.reset_password',
    loadComponent: () =>
      import('./password-reset/reset-password-page').then((m) => m.ResetPasswordPage),
  },
  {
    // A notification mail's stop link, opened from a mail client with no session: outside both guards, like a reset link.
    path: 'notifications/stop/:token',
    title: 'titles.notification_stop',
    loadComponent: () => import('./notifications/stop-mail-page').then((m) => m.StopMailPage),
  },
  {
    // A company its subscription locked: outside the shell like the page below, because the API refuses its members
    // everything but reading the subscription and declaring a payment — which is what this page is for.
    path: 'subscription',
    title: 'licensing.title',
    canActivate: [lockedSubscriptionGuard],
    loadComponent: () =>
      import('./licensing/locked-subscription-page').then((m) => m.LockedSubscriptionPage),
  },
  {
    // A member of a company that is not active, pending approval or suspended: outside the shell, which could not load.
    path: 'awaiting-approval',
    title: 'titles.awaiting_approval',
    canActivate: [awaitingApprovalGuard],
    loadComponent: () =>
      import('./auth/awaiting-approval-page').then((m) => m.AwaitingApprovalPage),
  },
  {
    // « Mon compte » for a member of a shut company, outside the shell: matched only then, so the shell's own
    // `account` below serves everyone else at the same address.
    path: 'account',
    title: 'account.title',
    canMatch: [closedCompanyMatch],
    ...textsPart('account', textsOpened(MFA_LOCKOUT_TEXTS)),
    loadComponent: () => import('./account/closed-account-page').then((m) => m.ClosedAccountPage),
  },
  {
    // The customer screen (docs/SPEC.md § 7, 2026-10-03 08:20): the one screen a customer may be left in front of.
    // Outside the shell, so it carries no menu, no palette and no scan card; `customerScreenLock` on every other
    // signed-in route sends the tab back here while it is open.
    path: 'customer-screen',
    title: 'customer_screen.title',
    canActivate: [authGuard],
    loadComponent: () =>
      import('./customer-screen/customer-screen-page').then((m) => m.CustomerScreenPage),
  },
  {
    // Every signed-in page is a child of the shell, which carries the navigation and the account menu.
    path: '',
    canActivate: [authGuard, customerScreenLock],
    loadComponent: () => import('./shell/app-shell').then((m) => m.AppShell),
    // Guarded as one list, not one route at a time: a record page added below would otherwise be able to be
    // left with unsaved changes, and nothing would say so (row 45).
    children: guardUnsaved([
      {
        path: '',
        title: 'nav.home',
        pathMatch: 'full',
        loadComponent: () => import('./hello/hello-page').then((m) => m.HelloPage),
      },
      {
        path: 'watch',
        title: 'watch.title',
        canActivate: [permissionGuard(MANAGE_NAV, '/watch'), textsOpened(WATCH_TEXTS)],
        loadComponent: () => import('./watch/watch-page').then((m) => m.WatchPage),
      },
      {
        path: 'watch/:kind',
        title: 'watch.title',
        canActivate: [
          permissionGuard(MANAGE_NAV, '/watch'),
          textsOpened(WATCH_TEXTS),
          textsOpened(CHECK_TEXTS),
        ],
        loadComponent: () => import('./watch/watch-subject-page').then((m) => m.WatchSubjectPage),
      },
      {
        // « Mon compte »: the person's own account, apart from any company (docs/SPEC.md § 7, 2026-09-25 17:22).
        path: 'account',
        title: 'account.title',
        ...textsPart('account', textsOpened(MFA_LOCKOUT_TEXTS)),
        loadComponent: () => import('./account/account-page').then((m) => m.AccountPage),
      },
      {
        // « Courriers de la démo »: what a sandbox would have mailed its visitor, kept instead (docs/SPEC.md § 7).
        path: 'demo-mails',
        title: 'demo_mails.title',
        ...textsPart('demo-mails'),
        loadComponent: () => import('./demo/demo-mails-page').then((m) => m.DemoMailsPage),
      },
      {
        // Where a page the person's role, or the company's subscription, keeps closed sends them: it names the page
        // and why, and offers the way home (hunt P-2).
        path: 'not-allowed',
        title: 'titles.not_allowed',
        ...textsPart('not-allowed'),
        loadComponent: () => import('./shell/not-allowed-page').then((m) => m.NotAllowedPage),
      },
      {
        // What the vision holds and is not built yet (docs/SPEC.md § 7, 2026-09-25 17:22).
        path: 'coming/:key',
        title: 'titles.coming',
        loadComponent: () => import('./shell/coming-page').then((m) => m.ComingPage),
      },
      {
        path: 'customers',
        title: 'nav.customers',
        canActivate: [
          moduleGuard(CUSTOMERS_MODULE),
          permissionGuard(CUSTOMERS_NAV, '/customers'),
          textsOpened(RECORDS_TEXTS),
          textsOpened(CHECK_TEXTS),
        ],
        loadComponent: () => import('./customers/customers-page').then((m) => m.CustomersPage),
      },
      {
        // Before ':customerId', which would otherwise take "new" and "groups" for identifiers.
        path: 'customers/new',
        title: 'titles.new_customer',
        canActivate: [
          moduleGuard(CUSTOMERS_MODULE),
          permissionGuard(CUSTOMERS_COMMANDS, '/customers/new'),
          textsOpened(CHECK_TEXTS),
        ],
        loadComponent: () => import('./customers/customer-page').then((m) => m.CustomerPage),
      },
      {
        path: 'customers/groups',
        title: 'nav.customer_groups',
        canActivate: [moduleGuard(CUSTOMERS_MODULE), permissionGuard(CUSTOMERS_NAV, '/customers')],
        loadComponent: () =>
          import('./customers/customer-groups-page').then((m) => m.CustomerGroupsPage),
      },
      {
        path: 'customers/:customerId',
        title: 'titles.customer',
        canActivate: [
          moduleGuard(CUSTOMERS_MODULE),
          permissionGuard(CUSTOMERS_NAV, '/customers'),
          textsOpened(RECORDS_TEXTS),
          textsOpened(CHECK_TEXTS),
        ],
        loadComponent: () => import('./customers/customer-page').then((m) => m.CustomerPage),
      },
      {
        path: 'price-lists',
        title: 'nav.price_lists',
        canActivate: [
          moduleGuard(PRICE_LISTS_MODULE),
          permissionGuard(PRICE_LISTS_NAV, '/price-lists'),
        ],
        loadComponent: () => import('./price-lists/price-lists-page').then((m) => m.PriceListsPage),
      },
      {
        path: 'products',
        title: 'nav.products',
        canActivate: [
          moduleGuard(PRODUCTS_MODULE),
          permissionGuard(PRODUCTS_NAV, '/products'),
          textsOpened(RECORDS_TEXTS),
          textsOpened(CHECK_TEXTS),
        ],
        loadComponent: () => import('./products/products-page').then((m) => m.ProductsPage),
      },
      {
        // Before ':productId', which would otherwise take "new" and "categories" for identifiers.
        path: 'products/new',
        title: 'titles.new_product',
        canActivate: [
          moduleGuard(PRODUCTS_MODULE),
          permissionGuard(PRODUCTS_COMMANDS, '/products/new'),
          textsOpened(CHECK_TEXTS),
        ],
        loadComponent: () => import('./products/product-page').then((m) => m.ProductPage),
      },
      {
        path: 'products/categories',
        title: 'nav.product_categories',
        canActivate: [
          moduleGuard(PRODUCTS_MODULE),
          permissionGuard(PRODUCTS_NAV, '/products'),
          textsOpened(RECORDS_TEXTS),
        ],
        loadComponent: () =>
          import('./products/product-categories-page').then((m) => m.ProductCategoriesPage),
      },
      {
        path: 'products/:productId',
        title: 'titles.product',
        canActivate: [
          moduleGuard(PRODUCTS_MODULE),
          permissionGuard(PRODUCTS_NAV, '/products'),
          textsOpened(RECORDS_TEXTS),
          textsOpened(CHECK_TEXTS),
        ],
        loadComponent: () => import('./products/product-page').then((m) => m.ProductPage),
      },
      {
        path: 'invoices',
        title: 'nav.invoices',
        canActivate: [
          moduleGuard(INVOICES_MODULE),
          permissionGuard(INVOICES_NAV, '/invoices'),
          textsOpened(DELIVERIES_TEXTS),
        ],
        loadComponent: () => import('./invoices/invoices-page').then((m) => m.InvoicesPage),
      },
      {
        // Before ':invoiceId', which would otherwise take "new" for an identifier.
        path: 'invoices/new',
        title: 'titles.new_invoice',
        canActivate: [
          moduleGuard(INVOICES_MODULE),
          permissionGuard(INVOICES_COMMANDS, '/invoices/new'),
        ],
        loadComponent: () => import('./invoices/invoice-page').then((m) => m.InvoicePage),
      },
      {
        path: 'invoices/recurring',
        title: 'recurring.title',
        // The schedule's words load with the recurring lists and the dialog that makes one (the screen texts budget).
        canActivate: [
          moduleGuard(RECURRING_MODULE),
          permissionGuard(INVOICES_NAV, '/invoices'),
          textsOpened(SCHEDULE_TEXTS),
        ],
        loadComponent: () => import('./recurring/recurring-page').then((m) => m.RecurringPage),
      },
      {
        path: 'invoices/:invoiceId',
        title: 'titles.invoice',
        canActivate: [
          moduleGuard(INVOICES_MODULE),
          permissionGuard(INVOICES_NAV, '/invoices'),
          textsOpened(DELIVERIES_TEXTS),
        ],
        loadComponent: () => import('./invoices/invoice-page').then((m) => m.InvoicePage),
      },
      {
        path: 'instruments',
        title: 'nav.instruments',
        canActivate: [moduleGuard(INVOICES_MODULE), permissionGuard(INVOICES_NAV, '/invoices')],
        loadComponent: () => import('./invoices/portfolio-page').then((m) => m.PortfolioPage),
      },
      {
        path: 'quotes',
        title: 'nav.quotes',
        canActivate: [moduleGuard(QUOTES_MODULE), permissionGuard(QUOTES_NAV, '/quotes')],
        loadComponent: () => import('./quotes/quotes-page').then((m) => m.QuotesPage),
      },
      {
        // Before ':quoteId', which would otherwise take "new" for an identifier.
        path: 'quotes/new',
        title: 'titles.new_quote',
        canActivate: [moduleGuard(QUOTES_MODULE), permissionGuard(QUOTES_COMMANDS, '/quotes/new')],
        loadComponent: () => import('./quotes/quote-page').then((m) => m.QuotePage),
      },
      {
        path: 'quotes/:quoteId',
        title: 'titles.quote',
        canActivate: [
          moduleGuard(QUOTES_MODULE),
          permissionGuard(QUOTES_NAV, '/quotes'),
          textsOpened(DELIVERIES_TEXTS),
        ],
        loadComponent: () => import('./quotes/quote-page').then((m) => m.QuotePage),
      },
      {
        path: 'delivery-notes',
        title: 'nav.delivery_notes',
        canActivate: [
          moduleGuard(DELIVERY_NOTES_MODULE),
          permissionGuard(DELIVERY_NOTES_NAV, '/delivery-notes'),
        ],
        loadComponent: () =>
          import('./delivery-notes/delivery-notes-page').then((m) => m.DeliveryNotesPage),
      },
      {
        // Before ':deliveryNoteId', which would otherwise take "new" for an identifier.
        path: 'delivery-notes/new',
        title: 'titles.new_delivery_note',
        canActivate: [
          moduleGuard(DELIVERY_NOTES_MODULE),
          permissionGuard(DELIVERY_NOTES_COMMANDS, '/delivery-notes/new'),
        ],
        loadComponent: () =>
          import('./delivery-notes/delivery-note-page').then((m) => m.DeliveryNotePage),
      },
      {
        path: 'delivery-notes/:deliveryNoteId',
        title: 'titles.delivery_note',
        canActivate: [
          moduleGuard(DELIVERY_NOTES_MODULE),
          permissionGuard(DELIVERY_NOTES_NAV, '/delivery-notes'),
          textsOpened(DELIVERIES_TEXTS),
        ],
        loadComponent: () =>
          import('./delivery-notes/delivery-note-page').then((m) => m.DeliveryNotePage),
      },
      {
        path: 'stock',
        title: 'nav.stock',
        canActivate: [
          moduleGuard(INVENTORY_MODULE),
          permissionGuard(INVENTORY_NAV, '/stock'),
          textsOpened(STOCK_COUNT_TEXTS),
        ],
        loadComponent: () => import('./inventory/stock-page').then((m) => m.StockPage),
      },
      {
        // Count mode (docs/SPEC.md § 7, 2026-09-23 slice 8): a scanner walking the shelves.
        path: 'stock/count',
        title: 'nav.stock_count',
        canActivate: [
          moduleGuard(INVENTORY_MODULE),
          permissionGuard(INVENTORY_NAV, '/stock'),
          permissionGuard(INVENTORY_TABS, '/stock/count'),
          textsOpened(STOCK_COUNT_TEXTS),
        ],
        loadComponent: () => import('./inventory/stock-count-page').then((m) => m.StockCountPage),
      },
      {
        // A location label's QR code (slice 8): count mode, at that location.
        path: 'stock/locations/:location',
        title: 'nav.stock_count',
        canActivate: [
          moduleGuard(INVENTORY_MODULE),
          permissionGuard(INVENTORY_NAV, '/stock'),
          permissionGuard(INVENTORY_TABS, '/stock/count'),
          textsOpened(STOCK_COUNT_TEXTS),
        ],
        loadComponent: () => import('./inventory/stock-count-page').then((m) => m.StockCountPage),
      },
      {
        path: 'stock/movements',
        title: 'nav.stock_movements',
        canActivate: [moduleGuard(INVENTORY_MODULE), permissionGuard(INVENTORY_NAV, '/stock')],
        loadComponent: () =>
          import('./inventory/stock-movements-page').then((m) => m.StockMovementsPage),
      },
      {
        path: 'stock/locations',
        title: 'nav.stock_locations',
        // A place's contents open over the list in the plan's own dialog, which reads the plan's texts.
        ...textsPart(
          'stock-plan',
          moduleGuard(INVENTORY_MODULE),
          permissionGuard(INVENTORY_NAV, '/stock'),
        ),
        loadComponent: () =>
          import('./inventory/stock-locations-page').then((m) => m.StockLocationsPage),
      },
      {
        path: 'stock/valuation',
        title: 'nav.stock_valuation',
        canActivate: [
          moduleGuard(INVENTORY_MODULE),
          permissionGuard(INVENTORY_NAV, '/stock'),
          permissionGuard(INVENTORY_TABS, '/stock/valuation'),
        ],
        loadComponent: () =>
          import('./inventory/stock-valuation-page').then((m) => m.StockValuationPage),
      },
      {
        path: 'stock/plan',
        title: 'titles.stock_plan',
        // The plan's texts are read on these pages only: they load with them (the screen texts budget).
        ...textsPart(
          'stock-plan',
          moduleGuard(INVENTORY_MODULE),
          permissionGuard(INVENTORY_NAV, '/stock'),
          textsOpened(STOCK_COUNT_TEXTS),
        ),
        loadComponent: () => import('./inventory/stock-map-page').then((m) => m.StockMapPage),
      },
      {
        // One screen for every subject a module declares as importable: the API already answers 404 for a subject
        // this company cannot import, or may not write, so no static module guard could say it here.
        path: 'imports/:subject',
        title: 'titles.import',
        // The records' words name what an import's undo kept and why, and its toast offers their « Annuler »; a row is
        // refused for the reasons a form's save is, a lot's or a quantity's, whose words are the forms' refusal texts.
        ...textsPart('import', textsOpened(RECORDS_TEXTS), textsOpened(FORM_REFUSAL_TEXTS)),
        loadComponent: () => import('./import/import-page').then((m) => m.ImportPage),
      },
      {
        path: 'vendors',
        title: 'nav.vendors',
        canActivate: [
          moduleGuard(VENDORS_MODULE),
          permissionGuard(VENDORS_NAV, '/vendors'),
          textsOpened(RECORDS_TEXTS),
          textsOpened(CHECK_TEXTS),
        ],
        loadComponent: () => import('./vendors/vendors-page').then((m) => m.VendorsPage),
      },
      {
        path: 'vendors/new',
        title: 'titles.new_vendor',
        canActivate: [
          moduleGuard(VENDORS_MODULE),
          permissionGuard(VENDORS_COMMANDS, '/vendors/new'),
          textsOpened(CHECK_TEXTS),
        ],
        loadComponent: () => import('./vendors/vendor-page').then((m) => m.VendorPage),
      },
      {
        path: 'vendors/:vendorId',
        title: 'titles.vendor',
        canActivate: [
          moduleGuard(VENDORS_MODULE),
          permissionGuard(VENDORS_NAV, '/vendors'),
          textsOpened(RECORDS_TEXTS),
          textsOpened(CHECK_TEXTS),
          textsOpened(PURCHASE_HISTORY_TEXTS),
        ],
        loadComponent: () => import('./vendors/vendor-page').then((m) => m.VendorPage),
      },
      {
        path: 'purchases',
        title: 'modules.purchases',
        // The orders' texts are read on these pages only: they load with them (the screen texts budget).
        ...textsPart(
          'purchases',
          moduleGuard(PURCHASES_MODULE),
          permissionGuard(PURCHASES_NAV, '/purchases'),
        ),
        loadComponent: () => import('./purchases/purchases-page').then((m) => m.PurchasesPage),
      },
      {
        path: 'purchases/new',
        title: 'nav.new_purchase',
        ...textsPart(
          'purchases',
          moduleGuard(PURCHASES_MODULE),
          permissionGuard(PURCHASES_COMMANDS, '/purchases/new'),
        ),
        loadComponent: () =>
          import('./purchases/purchase-order-page').then((m) => m.PurchaseOrderPage),
      },
      {
        path: 'purchases/:purchaseOrderId',
        title: 'modules.purchases',
        ...textsPart(
          'purchases',
          moduleGuard(PURCHASES_MODULE),
          permissionGuard(PURCHASES_NAV, '/purchases'),
        ),
        loadComponent: () =>
          import('./purchases/purchase-order-page').then((m) => m.PurchaseOrderPage),
      },
      {
        path: 'expenses',
        title: 'nav.expenses',
        canActivate: [moduleGuard(EXPENSES_MODULE), permissionGuard(EXPENSES_NAV, '/expenses')],
        loadComponent: () => import('./expenses/expenses-page').then((m) => m.ExpensesPage),
      },
      {
        path: 'expenses/categories',
        title: 'nav.expense_categories',
        canActivate: [
          moduleGuard(EXPENSES_MODULE),
          permissionGuard(EXPENSES_NAV, '/expenses'),
          textsOpened(RECORDS_TEXTS),
        ],
        loadComponent: () =>
          import('./expenses/expense-categories-page').then((m) => m.ExpenseCategoriesPage),
      },
      {
        // Before ':expenseId', which would otherwise take "recurring" for an identifier.
        path: 'expenses/recurring',
        title: 'modules.recurring_expenses',
        // Its texts are read on the expense pages only, the schedule's on every recurring list: both load with it (the
        // screen texts budget).
        ...textsPart(
          'expense',
          moduleGuard(RECURRING_EXPENSES_MODULE),
          permissionGuard(EXPENSES_NAV, '/expenses'),
          textsOpened(SCHEDULE_TEXTS),
        ),
        loadComponent: () =>
          import('./recurring-expenses/recurring-expenses-page').then(
            (m) => m.RecurringExpensesPage,
          ),
      },
      {
        path: 'expenses/new',
        title: 'titles.new_expense',
        // The bill's texts are read on this page only: they load with it (the screen texts budget).
        ...textsPart(
          'expense',
          moduleGuard(EXPENSES_MODULE),
          permissionGuard(EXPENSES_COMMANDS, '/expenses/new'),
        ),
        loadComponent: () => import('./expenses/expense-page').then((m) => m.ExpensePage),
      },
      {
        path: 'expenses/:expenseId',
        title: 'titles.expense',
        // The bill's texts are read on this page only: they load with it (the screen texts budget).
        ...textsPart(
          'expense',
          moduleGuard(EXPENSES_MODULE),
          permissionGuard(EXPENSES_NAV, '/expenses'),
        ),
        loadComponent: () => import('./expenses/expense-page').then((m) => m.ExpensePage),
      },
      {
        path: 'reports',
        title: 'modules.reports',
        // The reports' texts are read on these two pages only: they load with them (the screen texts budget).
        ...textsPart(
          'reports',
          moduleGuard(REPORTS_MODULE),
          permissionGuard(REPORTS_NAV, '/reports'),
        ),
        loadComponent: () => import('./reports/reports-page').then((m) => m.ReportsPage),
      },
      {
        path: 'reports/:key',
        title: 'modules.reports',
        ...textsPart(
          'reports',
          moduleGuard(REPORTS_MODULE),
          permissionGuard(REPORTS_NAV, '/reports'),
        ),
        loadComponent: () => import('./reports/report-page').then((m) => m.ReportPage),
      },
      {
        path: 'accounting-export',
        title: 'nav.accounting_export',
        canActivate: [
          moduleGuard(ACCOUNTING_EXPORT_MODULE),
          permissionGuard(ACCOUNTING_EXPORT_NAV, '/accounting-export'),
        ],
        loadComponent: () =>
          import('./accounting-export/accounting-export-page').then((m) => m.AccountingExportPage),
      },
      {
        // The platform's operators run signup and decide on the companies waiting for approval here.
        path: 'platform',
        title: 'platform.title',
        // The operators' texts are read on their two pages only: they load with them (the screen texts budget).
        ...textsPart('platform', operatorGuard),
        loadComponent: () => import('./platform/platform-page').then((m) => m.PlatformPage),
      },
      {
        // The legal pages as the operators write them, per language (docs/SPEC.md § 8 row 148).
        path: 'platform/legal',
        title: 'titles.legal_texts',
        ...textsPart('platform', operatorGuard),
        loadComponent: () =>
          import('./platform/platform-legal-page').then((m) => m.PlatformLegalPage),
      },
      {
        // The application's texts as the installation words them for every company: the same page, at its level.
        path: 'platform/texts',
        title: 'nav.texts',
        data: { level: 'installation' },
        ...textsPart('texts', operatorGuard),
        loadComponent: () => import('./texts/texts-page').then((m) => m.TextsPage),
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
            title: 'shell.settings',
            pathMatch: 'full',
            loadComponent: () => import('./shell/settings-index').then((m) => m.SettingsIndex),
          },
          {
            path: 'members',
            title: 'nav.members',
            canActivate: [permissionGuard(SETTINGS_NAV, '/members'), textsOpened(MEMBERS_TEXTS)],
            loadComponent: () => import('./company/members-page').then((m) => m.MembersPage),
          },
          {
            // A settings entry not built yet opens beside the settings list (docs/SPEC.md § 7, 2026-09-25 17:22).
            path: 'company/coming/:key',
            title: 'titles.coming',
            loadComponent: () => import('./shell/coming-page').then((m) => m.ComingPage),
          },
          {
            path: 'company/roles',
            title: 'nav.roles',
            canActivate: [
              permissionGuard(SETTINGS_NAV, '/company/roles'),
              textsOpened(ROLES_TEXTS),
            ],
            loadComponent: () => import('./company/roles-page').then((m) => m.RolesPage),
          },
          {
            path: 'company/activity',
            title: 'nav.activity',
            canActivate: [
              permissionGuard(SETTINGS_NAV, '/company/activity'),
              textsOpened(ACTIVITY_TEXTS),
            ],
            loadComponent: () => import('./activity/activity-page').then((m) => m.ActivityPage),
          },
          {
            path: 'fiscal/taxes',
            title: 'nav.taxes',
            canActivate: [permissionGuard(SETTINGS_NAV, '/fiscal/taxes')],
            loadComponent: () =>
              import('./fiscal/fiscal-taxes-page').then((m) => m.FiscalTaxesPage),
          },
          {
            path: 'fiscal/units',
            title: 'nav.units',
            canActivate: [permissionGuard(SETTINGS_NAV, '/fiscal/units')],
            loadComponent: () =>
              import('./fiscal/fiscal-units-page').then((m) => m.FiscalUnitsPage),
          },
          {
            path: 'settings',
            title: 'nav.settings',
            canActivate: [permissionGuard(SETTINGS_NAV, '/settings')],
            loadComponent: () => import('./settings/settings-page').then((m) => m.SettingsPage),
          },
          {
            path: 'company/profile',
            title: 'nav.company_profile',
            // route-open: « Fermer l'entreprise » sits on this page, and the owner of a company its subscription made
            // read-only, which narrows company.settings away, must still reach it to close the company.
            canActivate: [textsOpened(CHECK_TEXTS)],
            loadComponent: () =>
              import('./company/company-profile-page').then((m) => m.CompanyProfilePage),
          },
          {
            path: 'company/security',
            title: 'nav.company_security',
            canActivate: [permissionGuard(SETTINGS_NAV, '/company/security')],
            loadComponent: () =>
              import('./company/company-security-page').then((m) => m.CompanySecurityPage),
          },
          {
            path: 'company/support-access',
            title: 'nav.support_access',
            canActivate: [permissionGuard(SETTINGS_NAV, '/company/support-access')],
            loadComponent: () =>
              import('./support-access/support-access-page').then((m) => m.SupportAccessPage),
          },
          {
            path: 'company/recently-deleted',
            title: 'nav.recently_deleted',
            canActivate: [
              permissionGuard(SETTINGS_NAV, '/company/recently-deleted'),
              textsOpened(RECORDS_TEXTS),
            ],
            loadComponent: () =>
              import('./recently-deleted/recently-deleted-page').then((m) => m.RecentlyDeletedPage),
          },
          {
            path: 'company/data-erasure',
            title: 'nav.data_erasure',
            // Only the banner's words stay with the language's file: the banner shows on every page.
            ...textsPart('data-erasure', permissionGuard(SETTINGS_NAV, '/company/data-erasure')),
            loadComponent: () =>
              import('./data-erasure/data-erasure-page').then((m) => m.DataErasurePage),
          },
          {
            path: 'company/closing',
            title: 'nav.closing',
            canActivate: [permissionGuard(SETTINGS_NAV, '/company/closing')],
            loadComponent: () => import('./company/closing-page').then((m) => m.ClosingPage),
          },
          {
            path: 'company/establishments',
            title: 'nav.establishments',
            canActivate: [
              permissionGuard(SETTINGS_NAV, '/company/establishments'),
              textsOpened(CHECK_TEXTS),
            ],
            loadComponent: () =>
              import('./company/establishments-page').then((m) => m.EstablishmentsPage),
          },
          {
            path: 'company/numbering',
            title: 'nav.numbering',
            canActivate: [permissionGuard(SETTINGS_NAV, '/company/numbering')],
            loadComponent: () => import('./company/numbering-page').then((m) => m.NumberingPage),
          },
          {
            path: 'company/documents',
            title: 'nav.document_templates',
            canActivate: [permissionGuard(SETTINGS_NAV, '/company/documents')],
            loadComponent: () =>
              import('./company/document-design-page').then((m) => m.DocumentDesignPage),
          },
          {
            path: 'company/custom-fields',
            title: 'nav.custom_fields',
            canActivate: [permissionGuard(SETTINGS_NAV, '/company/custom-fields')],
            loadComponent: () =>
              import('./company/custom-fields-page').then((m) => m.CustomFieldsPage),
          },
          {
            path: 'company/modules',
            title: 'nav.modules',
            canActivate: [permissionGuard(SETTINGS_NAV, '/company/modules')],
            loadComponent: () => import('./company/modules-page').then((m) => m.ModulesPage),
          },
          {
            // The application's texts as the company words them, over the installation's.
            path: 'company/texts',
            title: 'nav.texts',
            ...textsPart('texts', permissionGuard(SETTINGS_NAV, '/company/texts')),
            loadComponent: () => import('./texts/texts-page').then((m) => m.TextsPage),
          },
          {
            // Reachable whatever the subscription says: a locked company reaches nothing else, and this is the
            // way out of it (docs/SPEC.md § 7, 2026-09-17).
            path: 'company/subscription',
            title: 'nav.subscription',
            canActivate: [permissionGuard(SETTINGS_NAV, '/company/subscription')],
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
    title: 'titles.legal',
    loadComponent: () => import('./legal/legal-page').then((m) => m.LegalPage),
  },
  { path: '**', redirectTo: '' },
];
