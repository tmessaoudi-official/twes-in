// SPDX-License-Identifier: AGPL-3.0-or-later

import type { FormDescriptor, FormField, FormValues } from '../shared/form/form-types';
import {
  changedSettingsAt,
  overridesAt,
  settingsForm,
  settingsValues,
  type SettingChange,
} from '../shared/settings/setting-forms';
import type { SettingChain, SettingRow } from '../shared/settings/settings-types';
import type { UnitRow } from '../fiscal/fiscal-types';

import { fieldIdOf } from '../shared/settings/setting-forms';

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

/**
 * The default unit is held as its code, which nobody reads (« C62 »): once the company's units are read, it is offered
 * as them, by name. Until then it stays the code the API validates, so the field is never a choice of nothing.
 */
export function withUnitChoice(form: FormDescriptor, units: readonly UnitRow[]): FormDescriptor {
  const offered = units.filter((unit) => unit.isActive);
  if (offered.length === 0) return form;
  const id = fieldIdOf('article.default_unit');
  return {
    ...form,
    sections: form.sections.map((section) => ({
      ...section,
      fields: section.fields.map((field) => {
        if (field.id !== id) return field;
        // A choice is valid by construction: the code's pattern would only refuse a unit's own code.
        const choice: FormField = {
          ...field,
          kind: 'select',
          options: offered.map((unit) => ({ value: unit.code, label: unit.name })),
        };
        delete choice.pattern;
        return choice;
      }),
    })),
  };
}
