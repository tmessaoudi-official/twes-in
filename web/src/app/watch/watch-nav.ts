// SPDX-License-Identifier: AGPL-3.0-or-later

import type { HomePanel } from '../shell/home-manifest';

/**
 * « À surveiller » on the home: its count, for whoever may read the company. It belongs to no module, since each
 * condition on it is gated by its own module and permission on the API.
 */
export const WATCH_HOME: readonly HomePanel[] = [
  {
    key: 'watch',
    permission: 'company.read',
    load: () => import('./watch-home').then((feature) => feature.WatchHome),
  },
];
