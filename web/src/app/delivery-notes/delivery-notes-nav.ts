// SPDX-License-Identifier: AGPL-3.0-or-later

import type { NavEntry } from '../shell/nav-manifest';

/** The key the API's module registry knows the delivery notes module by. */
export const DELIVERY_NOTES_MODULE = 'delivery_notes';

/** The delivery notes module's navigation, shown while the working company has the module on (docs/SPEC.md § 3 Modules). */
export const DELIVERY_NOTES_NAV: readonly NavEntry[] = [
  {
    key: 'delivery-notes',
    labelKey: 'nav.delivery_notes',
    icon: 'local_shipping',
    route: '/delivery-notes',
    section: 'main',
    permission: 'delivery_note.read',
    module: DELIVERY_NOTES_MODULE,
  },
];
