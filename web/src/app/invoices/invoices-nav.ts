// SPDX-License-Identifier: AGPL-3.0-or-later

import type { Command } from '../shell/commands';
import type { NavEntry } from '../shell/nav-manifest';

/** The key the API's module registry knows the invoices module by. */
export const INVOICES_MODULE = 'invoices';

/** The invoices module's navigation, first of the modules: it is what the company is paid through. */
export const INVOICES_NAV: readonly NavEntry[] = [
  {
    key: 'invoices',
    labelKey: 'nav.invoices',
    icon: 'receipt_long',
    route: '/invoices',
    section: 'main',
    permission: 'invoice.read',
    module: INVOICES_MODULE,
  },
];

/** What the module adds to the command palette (Ctrl K): drafting an invoice, for whoever may. */
export const INVOICES_COMMANDS: readonly Command[] = [
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
