// SPDX-License-Identifier: AGPL-3.0-or-later

import type { NavigateCommand } from '../shell/commands';
import type { NavEntry } from '../shell/nav-manifest';
import type { PageTab } from '../shared/ui/page-tabs';

/** The key the API's module registry knows the products module by. */
export const PRODUCTS_MODULE = 'products';

/** The products module's navigation, shown while the working company has the module on (docs/SPEC.md § 3 Modules). */
export const PRODUCTS_NAV: readonly NavEntry[] = [
  {
    key: 'products',
    labelKey: 'nav.products',
    icon: 'inventory_2',
    route: '/products',
    section: 'main',
    permission: 'product.read',
    module: PRODUCTS_MODULE,
  },
];

/** The products screens as tabs: the catalogue and the categories, one sidebar entry between them (docs/SPEC.md § 7, 2026-09-14). */
export const PRODUCTS_TABS: readonly PageTab[] = [
  { labelKey: 'nav.products', route: '/products', testId: 'products-tab' },
  {
    labelKey: 'nav.product_categories',
    route: '/products/categories',
    testId: 'product-categories-link',
  },
  { labelKey: 'price_check.title', route: '/products/price-check', testId: 'price-check-link' },
];

/** What the module adds to the command palette (Ctrl K): creating one, for whoever may. */
export const PRODUCTS_COMMANDS: readonly NavigateCommand[] = [
  {
    key: 'new-product',
    labelKey: 'products.new_title',
    icon: 'add_box',
    route: '/products/new',
    group: 'create',
    permission: 'product.write',
    module: PRODUCTS_MODULE,
  },
];
