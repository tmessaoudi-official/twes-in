// SPDX-License-Identifier: AGPL-3.0-or-later

import type { NavEntry } from '../shell/nav-manifest';

/** The key the API's module registry knows the accountant's files by. */
export const ACCOUNTING_EXPORT_MODULE = 'accounting_export';

/** « Export comptable », in Gérer after the expenses whose journal it holds. */
export const ACCOUNTING_EXPORT_NAV: readonly NavEntry[] = [
  {
    key: 'accounting_export',
    labelKey: 'nav.accounting_export',
    icon: 'output',
    route: '/accounting-export',
    section: 'manage',
    permission: 'accounting.export',
    module: ACCOUNTING_EXPORT_MODULE,
  },
];
