// SPDX-License-Identifier: AGPL-3.0-or-later

import type { Type } from '@angular/core';
import { INVOICES_HOME } from '../invoices/invoices-nav';
import { WATCH_HOME } from '../watch/watch-nav';
import type { Gated } from './nav-manifest';

/**
 * A panel of the home page, declared by a module's web feature beside its navigation and shown under the same gates:
 * the module on in the working company and the permission it names (docs/SPEC.md § 8 row 35). Its component is
 * loaded only once it is shown, so the home page's bundle carries no module's screens.
 */
export interface HomePanel extends Gated {
  readonly key: string;
  readonly load: () => Promise<Type<unknown>>;
}

/** Every module's home panels, in the order the page shows them. */
export const HOME_PANELS: readonly HomePanel[] = [...WATCH_HOME, ...INVOICES_HOME];
