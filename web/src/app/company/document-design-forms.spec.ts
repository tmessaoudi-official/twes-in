// SPDX-License-Identifier: AGPL-3.0-or-later

import type { SettingRow } from '../shared/settings/settings-types';
import { DEFAULT_DESIGN, designChanges, savedDesign } from './document-design-forms';

function designRow(key: string, defaultValue: string, company?: string): SettingRow {
  return {
    key,
    chain: 'parties',
    type: key === 'document.accent' ? 'colour' : 'enum',
    labelKey: `settings.${key}`,
    module: 'core',
    defaultValue,
    value: company ?? defaultValue,
    source: company === undefined ? null : 'company',
    levels: company === undefined ? [] : [{ level: 'company', value: company }],
    overridableLevels: ['company'],
    writableLevels: ['company'],
    choices: key === 'document.layout' ? ['classic', 'modern', 'compact'] : [],
    min: null,
    max: null,
    maxLength: null,
    pattern: null,
  };
}

describe('document design forms', () => {
  it('reads the design the company saved, else the platform’s default, and nothing before the settings arrive', () => {
    expect(
      savedDesign([
        designRow('document.layout', 'classic', 'modern'),
        designRow('document.accent', '#1f2328', '#1F6FEB'),
      ]),
    ).toEqual({ layout: 'modern', accent: '#1f6feb' });
    expect(
      savedDesign([
        designRow('document.layout', 'classic'),
        designRow('document.accent', '#1f2328'),
      ]),
    ).toEqual({ layout: 'classic', accent: '#1f2328' });
    expect(savedDesign([])).toEqual(DEFAULT_DESIGN);
  });

  it('changes only the settings the choice moved, the accent compared whatever its case', () => {
    const saved = { layout: 'classic', accent: '#1f6feb' } as const;

    expect(designChanges(saved, { layout: 'classic', accent: '#1F6FEB' })).toEqual([]);
    expect(designChanges(saved, { layout: 'compact', accent: '#2DA44E' })).toEqual([
      { key: 'document.layout', value: 'compact' },
      { key: 'document.accent', value: '#2da44e' },
    ]);
  });
});
