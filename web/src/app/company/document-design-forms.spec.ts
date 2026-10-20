// SPDX-License-Identifier: AGPL-3.0-or-later

import type { SettingRow } from '../shared/settings/settings-types';
import { DEFAULT_DESIGN, designChanges, logoRoom, savedDesign } from './document-design-forms';

const CHOICES: Readonly<Record<string, readonly string[]>> = {
  'document.layout': ['classic', 'modern', 'compact'],
  'document.watermark_intensity': ['discreet', 'normal', 'marked'],
  'document.watermark_copies': ['1', '3', '6', '12'],
  'document.watermark_spread': ['grouped', 'spread'],
};

function designRow(
  key: string,
  defaultValue: string | number | boolean,
  company?: string | number | boolean,
  bounds: [number, number] | null = null,
): SettingRow {
  return {
    key,
    chain: 'parties',
    type:
      key === 'document.accent'
        ? 'colour'
        : typeof defaultValue === 'number'
          ? 'int'
          : typeof defaultValue === 'boolean'
            ? 'bool'
            : 'enum',
    labelKey: `settings.${key}`,
    module: 'core',
    defaultValue,
    value: company ?? defaultValue,
    source: company === undefined ? null : 'company',
    levels: company === undefined ? [] : [{ level: 'company', value: company }],
    overridableLevels: ['company'],
    versions: company === undefined ? {} : { company: 1 },
    writableLevels: ['company'],
    choices: [...(CHOICES[key] ?? [])],
    min: bounds?.[0] ?? null,
    max: bounds?.[1] ?? null,
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
        designRow('document.logo_width', 48, 60),
        designRow('document.logo_height', 17),
        designRow('document.logo_keep_proportions', true, false),
        designRow('document.watermark_intensity', 'normal', 'marked'),
        designRow('document.watermark_copies', '1', '6'),
        designRow('document.watermark_spread', 'grouped', 'spread'),
      ]),
    ).toEqual({
      layout: 'modern',
      accent: '#1f6feb',
      logoWidth: 60,
      logoHeight: 17,
      logoKeepsProportions: false,
      watermarkIntensity: 'marked',
      watermarkCopies: '6',
      watermarkSpread: 'spread',
    });
    expect(
      savedDesign([
        designRow('document.layout', 'classic'),
        designRow('document.accent', '#1f2328'),
        designRow('document.logo_width', 48),
        designRow('document.logo_height', 17),
        designRow('document.logo_keep_proportions', true),
        designRow('document.watermark_intensity', 'normal'),
        designRow('document.watermark_copies', '1'),
        designRow('document.watermark_spread', 'grouped'),
      ]),
    ).toEqual({
      layout: 'classic',
      accent: '#1f2328',
      logoWidth: 48,
      logoHeight: 17,
      logoKeepsProportions: true,
      watermarkIntensity: 'normal',
      watermarkCopies: '1',
      watermarkSpread: 'grouped',
    });
    // A value the page does not know is read as the look documents always printed in.
    expect(savedDesign([designRow('document.watermark_copies', '1', '5')]).watermarkCopies).toBe(
      '1',
    );
    expect(savedDesign([])).toEqual(DEFAULT_DESIGN);
  });

  it('changes only the settings the choice moved, the accent compared whatever its case', () => {
    const saved = {
      layout: 'classic',
      accent: '#1f6feb',
      logoWidth: 48,
      logoHeight: 17,
      logoKeepsProportions: true,
      watermarkIntensity: 'normal',
      watermarkCopies: '1',
      watermarkSpread: 'grouped',
    } as const;

    expect(designChanges(saved, { ...saved, accent: '#1F6FEB' })).toEqual([]);
    expect(
      designChanges(saved, {
        layout: 'compact',
        accent: '#2DA44E',
        logoWidth: 60,
        logoHeight: 17,
        logoKeepsProportions: false,
        watermarkIntensity: 'discreet',
        watermarkCopies: '12',
        watermarkSpread: 'spread',
      }),
    ).toEqual([
      { key: 'document.layout', value: 'compact' },
      { key: 'document.accent', value: '#2da44e' },
      { key: 'document.logo_width', value: 60 },
      { key: 'document.logo_keep_proportions', value: false },
      { key: 'document.watermark_intensity', value: 'discreet' },
      { key: 'document.watermark_copies', value: '12' },
      { key: 'document.watermark_spread', value: 'spread' },
    ]);
  });

  it('takes the room a logo may have from what the API declares, not from numbers of its own', () => {
    expect(
      logoRoom([
        designRow('document.logo_width', 48, undefined, [10, 105]),
        designRow('document.logo_height', 17, undefined, [5, 70]),
      ]),
    ).toEqual({ width: { min: 10, max: 105 }, height: { min: 5, max: 70 } });
    expect(logoRoom([])).toBeNull();
  });
});
