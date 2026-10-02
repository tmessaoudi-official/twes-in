// SPDX-License-Identifier: AGPL-3.0-or-later

import type { NavEntry } from '../shell/nav-manifest';

/** The key the API's module registry knows the price-lists module by. */
export const PRICE_LISTS_MODULE = 'price_lists';

/** The price-lists module's navigation, shown while the working company has the module on. */
export const PRICE_LISTS_NAV: readonly NavEntry[] = [
  {
    key: 'price_lists',
    labelKey: 'nav.price_lists',
    icon: 'sell',
    route: '/price-lists',
    section: 'sell',
    permission: 'product.read',
    module: PRICE_LISTS_MODULE,
  },
];
