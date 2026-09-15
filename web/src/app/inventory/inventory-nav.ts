// SPDX-License-Identifier: AGPL-3.0-or-later

import type { NavEntry } from '../shell/nav-manifest';
import type { PageTab } from '../shared/ui/page-tabs';

/** The key the API's module registry knows the inventory module by. */
export const INVENTORY_MODULE = 'inventory';

/** The inventory module's navigation, shown while the working company has the module on (docs/SPEC.md § 3 Modules). */
export const INVENTORY_NAV: readonly NavEntry[] = [
  {
    key: 'stock',
    labelKey: 'nav.stock',
    icon: 'warehouse',
    route: '/stock',
    section: 'main',
    permission: 'stock.read',
    module: INVENTORY_MODULE,
  },
];

/** The stock screens as tabs: what is on hand, how it moved, and where it is kept. */
export const INVENTORY_TABS: readonly PageTab[] = [
  { labelKey: 'nav.stock', route: '/stock', testId: 'stock-tab' },
  { labelKey: 'nav.stock_movements', route: '/stock/movements', testId: 'stock-movements-tab' },
  { labelKey: 'nav.stock_locations', route: '/stock/locations', testId: 'stock-locations-tab' },
];
