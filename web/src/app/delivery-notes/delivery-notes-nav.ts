// SPDX-License-Identifier: AGPL-3.0-or-later

import type { NavigateCommand } from '../shell/commands';
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
    section: 'sell',
    permission: 'delivery_note.read',
    module: DELIVERY_NOTES_MODULE,
  },
];

/** What the module adds to the command palette (Ctrl K): creating one, for whoever may. */
export const DELIVERY_NOTES_COMMANDS: readonly NavigateCommand[] = [
  {
    key: 'new-delivery-note',
    labelKey: 'delivery_notes.new_title',
    icon: 'local_shipping',
    route: '/delivery-notes/new',
    group: 'create',
    permission: 'delivery_note.write',
    module: DELIVERY_NOTES_MODULE,
  },
];
