// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT, inject, InjectionToken } from '@angular/core';
import { PageMemoryStorage, type SettingsStorage } from '../shared/settings/settings-facade';

/**
 * Where the phone remembers that the home's offer to install was put aside: kept on the device, since installing is
 * the device's, whoever signs in on it. The account menu and Ctrl K still offer it.
 */
export const INSTALL_STORAGE = new InjectionToken<SettingsStorage>('INSTALL_STORAGE', {
  providedIn: 'root',
  factory: () => {
    try {
      // Reading the property itself throws when site data is blocked.
      return inject(DOCUMENT).defaultView?.localStorage ?? new PageMemoryStorage();
    } catch {
      return new PageMemoryStorage();
    }
  },
});

export const INSTALL_LATER_KEY = 'twes.install.later';

export function installPutAside(storage: SettingsStorage): boolean {
  try {
    return storage.getItem(INSTALL_LATER_KEY) !== null;
  } catch {
    return false;
  }
}

export function putInstallAside(storage: SettingsStorage): void {
  try {
    storage.setItem(INSTALL_LATER_KEY, 'true');
  } catch {
    // Storage refused (site data blocked or full): the card goes for this page, and comes back at the next start.
  }
}
