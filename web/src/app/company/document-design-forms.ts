// SPDX-License-Identifier: AGPL-3.0-or-later

import { fieldIdOf, settingsValues, type SettingChange } from '../shared/settings/setting-forms';
import type { SettingRow } from '../shared/settings/settings-types';
import {
  DESIGN_SETTINGS,
  DOCUMENT_LAYOUTS,
  type DocumentDesign,
  type DocumentLayout,
  type LogoRoom,
} from './document-design-types';

/**
 * The form before the settings arrive: the classic layout, no accent and no logo room, which leaves the form invalid so
 * nothing is previewed or saved. What a company starts with is the API's, carried by the settings' rows.
 */
export const DEFAULT_DESIGN: DocumentDesign = {
  layout: 'classic',
  accent: '',
  logoWidth: null,
  logoHeight: null,
  logoKeepsProportions: true,
};

/** The design the company's documents print in now: its own values, else the platform's default. */
export function savedDesign(rows: readonly SettingRow[]): DocumentDesign {
  const values = settingsValues(rows, 'company');
  const layout = values[fieldIdOf(DESIGN_SETTINGS.layout)];
  const accent = values[fieldIdOf(DESIGN_SETTINGS.accent)];
  const logoWidth = values[fieldIdOf(DESIGN_SETTINGS.logoWidth)];
  const logoHeight = values[fieldIdOf(DESIGN_SETTINGS.logoHeight)];
  const keeps = values[fieldIdOf(DESIGN_SETTINGS.logoKeepsProportions)];
  return {
    layout: DOCUMENT_LAYOUTS.includes(layout as DocumentLayout)
      ? (layout as DocumentLayout)
      : DEFAULT_DESIGN.layout,
    accent: typeof accent === 'string' ? accent.toLowerCase() : DEFAULT_DESIGN.accent,
    logoWidth: typeof logoWidth === 'number' ? logoWidth : DEFAULT_DESIGN.logoWidth,
    logoHeight: typeof logoHeight === 'number' ? logoHeight : DEFAULT_DESIGN.logoHeight,
    logoKeepsProportions: keeps !== false,
  };
}

/** The bounds of the logo's room, from the settings' own declarations; null until they are read. */
export function logoRoom(rows: readonly SettingRow[]): LogoRoom | null {
  const bounds = (key: string) => {
    const row = rows.find((one) => one.key === key);
    return typeof row?.min === 'number' && typeof row.max === 'number'
      ? { min: row.min, max: row.max }
      : null;
  };
  const width = bounds(DESIGN_SETTINGS.logoWidth);
  const height = bounds(DESIGN_SETTINGS.logoHeight);
  return width && height ? { width, height } : null;
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
  if (chosen.logoWidth !== null && chosen.logoWidth !== saved.logoWidth) {
    changes.push({ key: DESIGN_SETTINGS.logoWidth, value: chosen.logoWidth });
  }
  if (chosen.logoHeight !== null && chosen.logoHeight !== saved.logoHeight) {
    changes.push({ key: DESIGN_SETTINGS.logoHeight, value: chosen.logoHeight });
  }
  if (chosen.logoKeepsProportions !== saved.logoKeepsProportions) {
    changes.push({ key: DESIGN_SETTINGS.logoKeepsProportions, value: chosen.logoKeepsProportions });
  }
  return changes;
}
