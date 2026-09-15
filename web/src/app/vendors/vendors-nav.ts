// SPDX-License-Identifier: AGPL-3.0-or-later

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
