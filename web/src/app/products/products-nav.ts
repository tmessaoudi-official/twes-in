// SPDX-License-Identifier: AGPL-3.0-or-later

import type { NavEntry } from '../shell/nav-manifest';

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
  {
    key: 'product-categories',
    labelKey: 'nav.product_categories',
    icon: 'category',
    route: '/products/categories',
    section: 'main',
    permission: 'product.read',
    module: PRODUCTS_MODULE,
  },
];
