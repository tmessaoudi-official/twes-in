// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT, inject, InjectionToken } from '@angular/core';
import { PageMemoryStorage, type SettingsStorage } from '../shared/settings/settings-facade';

/**
 * Where a floor's plan, arranged and not saved yet, is kept on this device: a closed tab, a crash or a page left by
 * mistake then costs nothing, and the plan is offered again (« Reprendre ») the next time the floor is opened here.
 * Kept per company and floor, on the device only: nothing of it reaches the API before « Enregistrer ».
 */
export const PLAN_DRAFT_STORAGE = new InjectionToken<SettingsStorage>('PLAN_DRAFT_STORAGE', {
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

export function planDraftKey(companyId: string, floorId: string): string {
  return `twes.stock-plan.${companyId}.${floorId}`;
}
