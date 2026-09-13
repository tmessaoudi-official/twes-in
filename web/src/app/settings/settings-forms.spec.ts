// SPDX-License-Identifier: AGPL-3.0-or-later

import type { SettingRow } from '../shared/settings/settings-types';
import {
  changedSettings,
  companyOverrides,
  companySettingsForm,
  companySettingsValues,
} from './settings-forms';

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
    overridableLevels: ['company'],
    writableLevels: ['company'],
    choices: [],
    min: null,
    max: null,
    maxLength: null,
    pattern: null,
    ...partial,
  };
}

const terms = row({
  key: 'document.payment_terms_days',
  chain: 'parties',
  type: 'int',
  defaultValue: 30,
  value: 45,
  source: 'company',
  levels: [{ level: 'company', value: 45 }],
  min: 0,
  max: 365,
});
const notes = row({
  key: 'document.printed_notes',
  chain: 'parties',
  type: 'text',
  defaultValue: '',
  value: '',
  maxLength: 2000,
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
  pattern: '/^[A-Z0-9]{2,3}$/',
});
const tracking = row({
  key: 'article.stock_tracking',
  chain: 'articles',
  type: 'bool',
  defaultValue: false,
  value: false,
});
// A person's own accent is theirs: the company form shows the company's value, here the default.
const accent = row({
  key: 'presentation.accent',
  chain: 'presentation',
  type: 'colour',
  defaultValue: '#1f6feb',
  value: '#000000',
  source: 'user',
  levels: [{ level: 'user', value: '#000000' }],
  writableLevels: ['company', 'role', 'user'],
});
const layout = row({
  key: 'presentation.list.members',
  chain: 'presentation',
  type: 'json',
  writableLevels: ['user'],
});
const rows = [terms, notes, language, unit, tracking, accent, layout];

describe('companySettingsForm', () => {
  it('renders one section per chain and one field per setting the company level holds, by type', () => {
    const form = companySettingsForm(rows);

    expect(
      form.sections.map((section) => [section.id, section.fields.map((f) => [f.id, f.kind])]),
    ).toEqual([
      [
        'parties',
        [
          ['document__payment_terms_days', 'number'],
          ['document__printed_notes', 'textarea'],
          ['document__language', 'select'],
        ],
      ],
      [
        'articles',
        [
          ['article__default_unit', 'text'],
          ['article__stock_tracking', 'checkbox'],
        ],
      ],
      ['presentation', [['presentation__accent', 'colour']]],
    ]);
    expect(form.sections.map((section) => section.title)).toEqual([
      'settings.chains.parties',
      'settings.chains.articles',
      'settings.chains.presentation',
    ]);
  });

  it("carries each setting's constraints to its field", () => {
    const fields = companySettingsForm(rows).sections.flatMap((section) => section.fields);
    const byId = Object.fromEntries(fields.map((field) => [field.id, field]));

    expect(byId['document__payment_terms_days']).toMatchObject({
      label: 'settings.document.payment_terms_days',
      required: true,
      min: 0,
      max: 365,
    });
    expect(byId['document__printed_notes']).toMatchObject({
      required: false,
      maxLength: 2000,
      span: 2,
    });
    expect(byId['document__language']?.options).toEqual([
      { value: 'fr', label: 'settings.choices.document.language.fr' },
      { value: 'en', label: 'settings.choices.document.language.en' },
    ]);
    expect(byId['article__default_unit']).toMatchObject({
      required: true,
      pattern: '[A-Z0-9]{2,3}',
    });
  });

  it('leaves out a section with nothing the person may set for the company', () => {
    expect(companySettingsForm([{ ...terms, writableLevels: ['user'] }]).sections).toEqual([]);
  });
});

describe('companySettingsValues', () => {
  it("shows the company's own value, else the default, never a person's own choice", () => {
    expect(companySettingsValues(rows)).toEqual({
      document__payment_terms_days: 45,
      document__printed_notes: '',
      document__language: 'fr',
      article__default_unit: 'C62',
      article__stock_tracking: false,
      presentation__accent: '#1f6feb',
    });
  });

  it("takes the platform's value when the company holds none, and the company's over it", () => {
    const platform = { level: 'platform' as const, value: 20 };
    const company = { level: 'company' as const, value: 60 };

    expect(companySettingsValues([{ ...terms, levels: [platform] }])).toEqual({
      document__payment_terms_days: 20,
    });
    expect(companySettingsValues([{ ...terms, levels: [company, platform] }])).toEqual({
      document__payment_terms_days: 60,
    });
    expect(companySettingsValues([{ ...terms, levels: [platform, company] }])).toEqual({
      document__payment_terms_days: 60,
    });
  });
});

describe('changedSettings', () => {
  it('names only the settings whose company value the form changed', () => {
    const values = {
      ...companySettingsValues(rows),
      document__payment_terms_days: 60,
      article__stock_tracking: true,
    };

    expect(changedSettings(rows, values)).toEqual([
      { key: 'document.payment_terms_days', value: 60 },
      { key: 'article.stock_tracking', value: true },
    ]);
    expect(changedSettings(rows, companySettingsValues(rows))).toEqual([]);
  });
});

describe('companyOverrides', () => {
  it('lists the settings the company holds a value for, which a reset returns to the default', () => {
    expect(companyOverrides(rows).map((setting) => setting.key)).toEqual([
      'document.payment_terms_days',
    ]);
  });
});
