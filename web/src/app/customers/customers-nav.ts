// SPDX-License-Identifier: AGPL-3.0-or-later

import type { NavigateCommand } from '../shell/commands';
import type { NavEntry } from '../shell/nav-manifest';
import type { PageTab } from '../shared/ui/page-tabs';

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
];

/** The customers screens as tabs: the list and the groups, one sidebar entry between them (docs/SPEC.md § 7, 2026-09-14). */
export const CUSTOMERS_TABS: readonly PageTab[] = [
  { labelKey: 'nav.customers', route: '/customers', testId: 'customers-tab' },
  { labelKey: 'nav.customer_groups', route: '/customers/groups', testId: 'customer-groups-link' },
];

/** What the module adds to the command palette (Ctrl K): creating one, for whoever may. */
export const CUSTOMERS_COMMANDS: readonly NavigateCommand[] = [
  {
    key: 'new-customer',
    labelKey: 'customers.new_title',
    icon: 'person_add',
    route: '/customers/new',
    group: 'create',
    permission: 'customer.write',
    module: CUSTOMERS_MODULE,
  },
];
