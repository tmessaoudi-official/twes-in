// SPDX-License-Identifier: AGPL-3.0-or-later

import type { NavigateCommand } from '../shell/commands';
import type { NavEntry } from '../shell/nav-manifest';

/** The key the API's module registry knows the quotes module by. */
export const QUOTES_MODULE = 'quotes';

/** The quotes module's navigation, after the invoices a quote becomes. */
export const QUOTES_NAV: readonly NavEntry[] = [
  {
    key: 'quotes',
    labelKey: 'nav.quotes',
    icon: 'request_quote',
    route: '/quotes',
    section: 'sell',
    permission: 'quote.read',
    module: QUOTES_MODULE,
  },
];

/** What the module adds to the command palette (Ctrl K): drafting a quote, for whoever may. */
export const QUOTES_COMMANDS: readonly NavigateCommand[] = [
  {
    key: 'new-quote',
    labelKey: 'quotes.new_title',
    icon: 'request_quote',
    route: '/quotes/new',
    group: 'create',
    permission: 'quote.write',
    module: QUOTES_MODULE,
  },
];
