// SPDX-License-Identifier: AGPL-3.0-or-later

import type { HomePanel } from '../shell/home-manifest';

/**
 * « Premiers pas » on the home, first of its panels, for whoever may read the company. It belongs to no module: each
 * step is gated by its own module and permission on the API, and the panel is gone once nothing remains.
 */
export const FIRST_STEPS_HOME: readonly HomePanel[] = [
  {
    key: 'first-steps',
    permission: 'company.read',
    load: () => import('./first-steps-home').then((feature) => feature.FirstStepsHome),
  },
];
