// SPDX-License-Identifier: AGPL-3.0-or-later

import type { NavigateCommand } from '../shell/commands';
import type { HomePanel } from '../shell/home-manifest';
import type { NavEntry } from '../shell/nav-manifest';
import type { Tour } from '../shared/tour/tour';

/** The key the API's module registry knows the invoices module by. */
export const INVOICES_MODULE = 'invoices';

/** The invoices module's navigation, first of the modules: it is what the company is paid through. */
export const INVOICES_NAV: readonly NavEntry[] = [
  {
    key: 'invoices',
    labelKey: 'nav.invoices',
    icon: 'receipt_long',
    route: '/invoices',
    section: 'sell',
    permission: 'invoice.read',
    module: INVOICES_MODULE,
  },
];

/** What the module adds to the command palette (Ctrl K): drafting an invoice, for whoever may. */
export const INVOICES_COMMANDS: readonly NavigateCommand[] = [
  {
    key: 'new-invoice',
    labelKey: 'invoices.new_title',
    icon: 'receipt_long',
    route: '/invoices/new',
    group: 'create',
    permission: 'invoice.write',
    module: INVOICES_MODULE,
  },
];

/** What the module shows on the home page: what is still to collect, the invoices to chase and the months' payments. */
export const INVOICES_HOME: readonly HomePanel[] = [
  {
    key: 'invoices',
    permission: 'invoice.read',
    module: INVOICES_MODULE,
    load: () => import('./invoices-home').then((feature) => feature.InvoicesHome),
  },
];

/** The module's guided tours: the first invoice, from the list to issuing it, for whoever may draft one. */
export const INVOICES_TOURS: readonly Tour[] = [
  {
    key: 'first-invoice',
    titleKey: 'invoices.tours.first_invoice.title',
    commandKey: 'invoices.tours.first_invoice.command',
    permission: 'invoice.write',
    module: INVOICES_MODULE,
    steps: [
      {
        anchor: 'list-search',
        route: '/invoices',
        titleKey: 'invoices.tours.first_invoice.list.title',
        bodyKey: 'invoices.tours.first_invoice.list.body',
      },
      {
        anchor: 'shell-create',
        titleKey: 'invoices.tours.first_invoice.create.title',
        bodyKey: 'invoices.tours.first_invoice.create.body',
      },
      {
        anchor: 'form',
        route: '/invoices/new',
        titleKey: 'invoices.tours.first_invoice.form.title',
        bodyKey: 'invoices.tours.first_invoice.form.body',
      },
      {
        anchor: 'document-actions',
        titleKey: 'invoices.tours.first_invoice.actions.title',
        bodyKey: 'invoices.tours.first_invoice.actions.body',
      },
    ],
  },
];
