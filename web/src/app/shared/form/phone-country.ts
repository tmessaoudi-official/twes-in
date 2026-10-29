// SPDX-License-Identifier: AGPL-3.0-or-later

import { InjectionToken } from '@angular/core';

/**
 * The country a phone field of the platform's own (the support's numbers, the legal publisher's and host's) reads a
 * number with no « + » by, and a company field's where no company is open. The platform keeps no country setting, so
 * it is the launch market's until one is wanted; a test or an installation provides its own.
 */
export const PLATFORM_PHONE_COUNTRY = new InjectionToken<string>('PLATFORM_PHONE_COUNTRY', {
  providedIn: 'root',
  factory: () => 'TN',
});
