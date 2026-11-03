// SPDX-License-Identifier: AGPL-3.0-or-later

import type { Type } from '@angular/core';
import { EXPENSES_HOME } from '../expenses/expenses-nav';
import { FIRST_STEPS_HOME } from '../first-steps/first-steps-nav';
import { INSTALL_HOME } from '../install/install-nav';
import { INVENTORY_HOME } from '../inventory/inventory-nav';
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
  /** Its width on the home's twelve columns from a laptop up: the whole row (the default) or half of it. */
  readonly span?: 'full' | 'half';
}

/**
 * Every module's home panels, in the order the page shows them, one order for every profile (D5): the install offer a
 * phone may take, « Premiers pas » right under the header while steps remain (T2), the invoices' figures across the
 * page, then the half-width panels side by side.
 */
export const HOME_PANELS: readonly HomePanel[] = [
  ...INSTALL_HOME,
  ...FIRST_STEPS_HOME,
  ...INVOICES_HOME,
  ...WATCH_HOME,
  ...INVENTORY_HOME,
  ...EXPENSES_HOME,
];

/**
 * Why a module of the menu brings no panel to the home, by its key: each module either declares a `*_HOME` beside its
 * `*_NAV` or says here why not (the class « a module contributes no home panel », plan row S7.5). A reason is a
 * figure with nothing behind it to show, or a home place already given to it elsewhere, never a panel forgotten.
 */
export const NO_HOME_PANEL: Readonly<Record<string, string>> = {
  quotes:
    'the quotes awaiting an answer are a tile of the lively home (plan row L3, « Devis en attente »)',
  delivery_notes:
    'the delivery notes to invoice are a tile of the lively home (plan row L3, « BL à facturer »)',
  purchases:
    'the orders whose goods are due are « À surveiller »’s (purchase_order.to_receive), one place on the home',
  customers:
    'what customers owe is the invoices panel’s: « À encaisser », « En retard », « À relancer »',
  products:
    'a catalogue has no figure of the day; its prices to look at reach « À surveiller » and the notifications',
  vendors: 'no balance is kept per vendor; what is ordered from them is the purchases module’s',
  price_lists:
    'a price list is a setting of prices, with no figure that moves from one day to the next',
  reports:
    'a report is read on demand, for a period chosen; its figures come from the modules shown here',
  accounting_export:
    'an export is made at a period’s end, on demand, and leaves nothing on hand to watch between two',
};
