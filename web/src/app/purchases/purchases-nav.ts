// SPDX-License-Identifier: AGPL-3.0-or-later

import type { NavigateCommand } from '../shell/commands';
import type { NavEntry } from '../shell/nav-manifest';

/** The key the API's module registry knows the orders to vendors by. */
export const PURCHASES_MODULE = 'purchases';

/** The orders to vendors, beside the vendors they go to, while the working company has the module on. */
export const PURCHASES_NAV: readonly NavEntry[] = [
  {
    key: 'purchases',
    labelKey: 'modules.purchases',
    icon: 'shopping_cart',
    route: '/purchases',
    section: 'manage',
    permission: 'purchase.read',
    module: PURCHASES_MODULE,
  },
];

/** What the module adds to the command palette (Ctrl K): an order to a vendor, for whoever may draft one. */
export const PURCHASES_COMMANDS: readonly NavigateCommand[] = [
  {
    key: 'new-purchase',
    labelKey: 'nav.new_purchase',
    icon: 'shopping_cart',
    route: '/purchases/new',
    group: 'create',
    permission: 'purchase.write',
    module: PURCHASES_MODULE,
  },
];
