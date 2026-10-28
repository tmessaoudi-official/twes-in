// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  changedSettingsAt,
  heldAt,
  overridesAt,
  settingsForm,
  settingsValues,
} from './setting-forms';
import { buildFormGroup } from '../form/form-builder';
import { apiPattern, hintExamples } from '../testing/hint-examples';
import type { SettingRow } from './settings-types';

function row(
  partial: Partial<SettingRow> & Pick<SettingRow, 'key' | 'chain' | 'type'>,
): SettingRow {
  return {
    labelKey: `settings.${partial.key}`,
    module: 'core',
    defaultValue: null,
    value: null,
    source: null,
    levels: [],
    overridableLevels: ['company', 'customer_group', 'customer', 'document'],
    versions: {},
    writableLevels: ['customer'],
    choices: [],
    min: null,
    max: null,
    maxLength: null,
    pattern: null,
    ...partial,
  };
}

// As a customer of a group sees them: the company says 60 days, the group 45.
const terms = row({
  key: 'document.payment_terms_days',
  chain: 'parties',
  type: 'int',
  defaultValue: 30,
  value: 45,
  source: 'customer_group',
  levels: [
    { level: 'company', value: 60 },
    { level: 'customer_group', value: 45 },
  ],
  min: 0,
  max: 365,
});
const language = row({
  key: 'document.language',
  chain: 'parties',
  type: 'enum',
  defaultValue: 'fr',
  value: 'fr',
  choices: ['fr', 'en'],
});
const unit = row({
  key: 'article.default_unit',
  chain: 'articles',
  type: 'text',
  defaultValue: 'C62',
  value: 'C62',
  overridableLevels: ['company', 'product_category', 'product'],
  versions: {},
  writableLevels: ['company'],
});
const rows = [terms, language, unit];
const customer = { id: 'customer-defaults', level: 'customer', chains: ['parties'] } as const;

describe('settings at a level', () => {
  // Audit 2026-10-06 V-15: a sentence as a label pushed its input below its neighbour's.
  it('says what a setting does as a hint under a short label, where one is declared', () => {
    const late = row({ key: 'watch.late_after_days', chain: 'parties', type: 'int', value: 30 });
    const [field, other] = settingsForm([late, terms], customer).sections.flatMap(
      (section) => section.fields,
    );
    expect(field.hint).toBe('settings.watch.late_after_days_hint');
    expect(other.hint).toBeUndefined();
  });

  // docs/SPEC.md § 7, 2026-09-19 21:55: a decimal or a money setting shows and takes the locale's decimal separator.
  it('asks a decimal or a money setting as a decimal field', () => {
    const rate = row({
      key: 'document.late_rate',
      chain: 'parties',
      type: 'decimal',
      value: '1.5',
    });
    const fee = row({ key: 'document.late_fee', chain: 'parties', type: 'money', value: '10.000' });
    const fields = settingsForm([rate, fee], customer).sections.flatMap(
      (section) => section.fields,
    );
    expect(fields.map((field) => [field.id, field.kind])).toEqual([
      ['document__late_rate', 'decimal'],
      ['document__late_fee', 'decimal'],
    ]);
  });

  // The hint's own example, copied with its no-break spaces, passes the pattern the API sends (« 5 ; 10 ; 2 % »).
  it('takes the late fee example its hint and its refusal offer, as copied', () => {
    const tiers = row({
      key: 'late_fees.tiers',
      chain: 'parties',
      type: 'text',
      defaultValue: '',
      value: '',
      pattern: apiPattern('late_fee_tiers'),
      patternReason: 'invalid_late_fee_tiers',
    });
    const control = buildFormGroup(settingsForm([tiers], customer)).controls['late_fees__tiers']!;
    const examples = hintExamples('late_fee_tiers');
    expect(examples).toContain('5\u202f; 10\u202f; 2\u00a0%');
    for (const example of examples) {
      control.setValue(example);
      expect(control.errors, example).toBeNull();
    }
    control.setValue('\u0665');
    expect(control.errors?.['pattern'], 'digits other than 0 to 9').toBeTruthy();
  });

  it('renders the settings that level may hold, in the chains asked for', () => {
    const form = settingsForm(rows, customer);

    expect(form.id).toBe('customer-defaults');
    expect(form.sections.map((section) => [section.id, section.fields.map((f) => f.id)])).toEqual([
      ['parties', ['document__payment_terms_days', 'document__language']],
    ]);
  });

  it('shows a reader what the level may hold, and a writer only what they may set', () => {
    const reader = rows.map((each) => ({ ...each, writableLevels: [] }));

    expect(settingsForm(reader, customer).sections).toEqual([]);
    expect(settingsForm(reader, { ...customer, readOnly: true }).sections[0]?.fields).toHaveLength(
      2,
    );
  });

  it("starts each field at the level's own value, else what the levels above it say, else the default", () => {
    expect(settingsValues(rows, 'customer')).toEqual({
      document__payment_terms_days: 45,
      document__language: 'fr',
    });
    expect(settingsValues(rows, 'customer_group')).toMatchObject({
      document__payment_terms_days: 45,
    });

    const own = { ...terms, levels: [...terms.levels, { level: 'customer' as const, value: 10 }] };
    expect(settingsValues([own], 'customer')).toEqual({ document__payment_terms_days: 10 });
    expect(settingsValues([own], 'customer_group')).toEqual({ document__payment_terms_days: 45 });
  });

  it('names only what the form changed at that level, and what the level holds', () => {
    const values = { ...settingsValues(rows, 'customer'), document__language: 'en' };

    expect(changedSettingsAt(rows, values, 'customer')).toEqual([
      { key: 'document.language', value: 'en' },
    ]);
    expect(overridesAt(rows, 'customer')).toEqual([]);
    expect(overridesAt(rows, 'customer_group', true).map((setting) => setting.key)).toEqual([
      'document.payment_terms_days',
    ]);
  });
});

describe('a reset taken back', () => {
  it('keeps what the level held of its own, and nothing for a level holding nothing', () => {
    expect(heldAt(rows, 'document.payment_terms_days', 'customer_group')).toEqual({ value: 45 });
    // The company's 60 is held even though the group's 45 is what applies.
    expect(heldAt(rows, 'document.payment_terms_days', 'company')).toEqual({ value: 60 });
    expect(heldAt(rows, 'document.payment_terms_days', 'customer')).toBeNull();
    expect(heldAt(rows, 'gone.key', 'company')).toBeNull();
  });
});
