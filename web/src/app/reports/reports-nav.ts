// SPDX-License-Identifier: AGPL-3.0-or-later

import type { NavEntry } from '../shell/nav-manifest';

/** The key the API's module registry knows the reports by. */
export const REPORTS_MODULE = 'reports';

/**
 * « Rapports », in Gérer after the expenses. The catalogue answers company.read; each report inside is offered only to a
 * role holding its subject's permission, so a member who may read nothing sees an empty area, not a missing one.
 */
export const REPORTS_NAV: readonly NavEntry[] = [
  {
    key: 'reports',
    labelKey: 'modules.reports',
    icon: 'bar_chart',
    route: '/reports',
    section: 'manage',
    permission: 'company.read',
    module: REPORTS_MODULE,
  },
];
