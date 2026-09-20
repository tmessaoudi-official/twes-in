// SPDX-License-Identifier: AGPL-3.0-or-later

import type { NavigateCommand } from '../shell/commands';
import type { NavEntry } from '../shell/nav-manifest';

/** The key the API's module registry knows the vendors module by. */
export const VENDORS_MODULE = 'vendors';

/** The vendors module's navigation, shown while the working company has the module on (docs/SPEC.md § 3 Modules). */
export const VENDORS_NAV: readonly NavEntry[] = [
  {
    key: 'vendors',
    labelKey: 'nav.vendors',
    icon: 'storefront',
    route: '/vendors',
    section: 'main',
    permission: 'vendor.read',
    module: VENDORS_MODULE,
  },
];

/** What the module adds to the command palette (Ctrl K): creating one, for whoever may. */
export const VENDORS_COMMANDS: readonly NavigateCommand[] = [
  {
    key: 'new-vendor',
    labelKey: 'vendors.new_title',
    icon: 'storefront',
    route: '/vendors/new',
    group: 'create',
    permission: 'vendor.write',
    module: VENDORS_MODULE,
  },
];
