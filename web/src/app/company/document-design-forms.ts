// SPDX-License-Identifier: AGPL-3.0-or-later

import { fieldIdOf, settingsValues, type SettingChange } from '../shared/settings/setting-forms';
import type { SettingRow } from '../shared/settings/settings-types';
import {
  DESIGN_SETTINGS,
  DOCUMENT_LAYOUTS,
  type DocumentDesign,
  type DocumentLayout,
} from './document-design-types';

/**
 * The form before the settings arrive: the classic layout and no accent, which leaves the form invalid so nothing is
 * previewed or saved. The accent a company starts with is the API's, carried by the setting's row.
 */
export const DEFAULT_DESIGN: DocumentDesign = { layout: 'classic', accent: '' };

/** The design the company's documents print in now: its own values, else the platform's default. */
export function savedDesign(rows: readonly SettingRow[]): DocumentDesign {
  const values = settingsValues(rows, 'company');
  const layout = values[fieldIdOf(DESIGN_SETTINGS.layout)];
  const accent = values[fieldIdOf(DESIGN_SETTINGS.accent)];
  return {
    layout: DOCUMENT_LAYOUTS.includes(layout as DocumentLayout)
      ? (layout as DocumentLayout)
      : DEFAULT_DESIGN.layout,
    accent: typeof accent === 'string' ? accent.toLowerCase() : DEFAULT_DESIGN.accent,
  };
}

/** The settings a chosen design changes from the saved one, each written at the company's level. */
export function designChanges(saved: DocumentDesign, chosen: DocumentDesign): SettingChange[] {
  const changes: SettingChange[] = [];
  if (chosen.layout !== saved.layout) {
    changes.push({ key: DESIGN_SETTINGS.layout, value: chosen.layout });
  }
  if (chosen.accent.toLowerCase() !== saved.accent.toLowerCase()) {
    changes.push({ key: DESIGN_SETTINGS.accent, value: chosen.accent.toLowerCase() });
  }
  return changes;
}
