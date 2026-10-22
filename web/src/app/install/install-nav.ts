// SPDX-License-Identifier: AGPL-3.0-or-later

import type { HomePanel } from '../shell/home-manifest';

/**
 * The offer to install the application, first on a phone's home. It belongs to no module and needs no permission:
 * installing is the device's. The card draws nothing on a wider window or where nothing can be installed.
 */
export const INSTALL_HOME: readonly HomePanel[] = [
  {
    key: 'install',
    load: () => import('./install-home').then((feature) => feature.InstallHome),
  },
];
