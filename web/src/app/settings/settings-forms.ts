// SPDX-License-Identifier: AGPL-3.0-or-later

import type { FormDescriptor, FormValues } from '../shared/form/form-types';
import {
  changedSettingsAt,
  overridesAt,
  settingsForm,
  settingsValues,
  type SettingChange,
} from '../shared/settings/setting-forms';
import type { SettingChain, SettingRow } from '../shared/settings/settings-types';

export { fieldIdOf, type SettingChange } from '../shared/settings/setting-forms';

/** The chains a company sets defaults in, in the order the page shows them. */
export const COMPANY_CHAINS: readonly SettingChain[] = [
  'parties',
  'articles',
  'presentation',
  'venue',
];

/** The company's defaults as one form: one section per chain, one field per setting the person may set there. */
export function companySettingsForm(rows: readonly SettingRow[]): FormDescriptor {
  return settingsForm(rows, { id: 'company-settings', level: 'company', chains: COMPANY_CHAINS });
}

/** Each field starts at the company's own value, else the platform's, else the default; a person's own choice is not the company's. */
export function companySettingsValues(rows: readonly SettingRow[]): FormValues {
  return settingsValues(rows, 'company');
}

/** The settings whose company value the submitted form changed, and their new values. */
export function changedSettings(rows: readonly SettingRow[], values: FormValues): SettingChange[] {
  return changedSettingsAt(rows, values, 'company');
}

/** The settings the company holds a value for; a reset returns each to the level above. */
export function companyOverrides(rows: readonly SettingRow[]): SettingRow[] {
  return overridesAt(rows, 'company');
}
