// SPDX-License-Identifier: AGPL-3.0-or-later

import type { IconName } from '../icons/icons';

/**
 * Something a screen will offer once a planned module ships (docs/SPEC.md § 7, 2026-09-26 10:08 and 18:17, row 150):
 * shown « Bientôt », never run, opening its module's « En construction » page. A document's bar and a record's bar
 * list them last in their « ⋮ », under their own heading (audit 2026-10-06 V-3): drawn first in the bar they pushed the
 * working actions onto a second row. They are not `ScreenAction`s: no key, no E, no line in the "?" sheet or the
 * palette, which already leads to each planned module. A module that ships leaves the catalogue's planned list, and
 * its actions vanish with it: the screen then declares the real ones.
 */
export interface PlannedAction {
  /** The planned module's key in the API's catalogue; the action is drawn only while the catalogue lists it. */
  readonly module: string;
  /** A translation key. */
  readonly label: string;
  /** A Material Symbols ligature. */
  readonly icon: IconName;
}
