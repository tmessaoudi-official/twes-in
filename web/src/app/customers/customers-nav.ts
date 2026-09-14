// SPDX-License-Identifier: AGPL-3.0-or-later

import type { NavEntry } from '../shell/nav-manifest';

/** The key the API's module registry knows the customers module by. */
export const CUSTOMERS_MODULE = 'customers';

/** The customers module's navigation, shown while the working company has the module on (docs/SPEC.md § 3 Modules). */
export const CUSTOMERS_NAV: readonly NavEntry[] = [
  {
    key: 'customers',
    labelKey: 'nav.customers',
    icon: 'contacts',
    route: '/customers',
    section: 'main',
    permission: 'customer.read',
    module: CUSTOMERS_MODULE,
  },
  {
    key: 'customer-groups',
    labelKey: 'nav.customer_groups',
    icon: 'folder_shared',
    route: '/customers/groups',
    section: 'main',
    permission: 'customer.read',
    module: CUSTOMERS_MODULE,
  },
];
