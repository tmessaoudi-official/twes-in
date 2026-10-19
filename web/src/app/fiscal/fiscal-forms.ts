// SPDX-License-Identifier: AGPL-3.0-or-later

import type {
  FieldValue,
  FormDescriptor,
  FormField,
  FormSection,
  FormValues,
} from '../shared/form/form-types';
import { DOCUMENT_LANGUAGES, nameOf, type Language } from '../shared/i18n/languages';
import type { ListFilter, ListDescriptor } from '../shared/list/list-types';
import {
  TAX_FAMILIES,
  type CustomerTaxRegimeRow,
  type LanguageNames,
  type TaxComponentInput,
  type TaxComponentRow,
  type TaxFamily,
  type UnitInput,
  type UnitRow,
} from './fiscal-types';

/** A percentage the API stores as NUMERIC(6,3). */
const RATE = '\\d{1,3}(\\.\\d{1,3})?';
/** An amount the API stores as NUMERIC(14,3); the currency may allow fewer decimals, which the API checks. */
const AMOUNT = '\\d{1,11}(\\.\\d{1,3})?';

const withRate: readonly TaxFamily[] = ['vat', 'levy', 'withholding'];

/** The API's longest names, in any language. */
const TAX_NAME_MAX = 120;
const UNIT_NAME_MAX = 60;

/** The field holding the name in one language documents print in. */
export const nameField = (language: Language): string => `name-${language}`;

/** One field per language documents print in, each labelled with the language's own name: blank, the name prints. */
function namesSection(maxLength: number): FormSection {
  return {
    id: 'names',
    title: 'fiscal.names.section',
    description: 'fiscal.names.description',
    fields: DOCUMENT_LANGUAGES.map((language) => ({
      id: nameField(language),
      label: 'fiscal.names.in',
      labelParams: { language: nameOf(language) },
      removeLabel: 'fiscal.names.remove',
      kind: 'text',
      maxLength,
    })),
  };
}

function namesValues(names: LanguageNames): FormValues {
  return Object.fromEntries(
    DOCUMENT_LANGUAGES.map((language) => [nameField(language), names[language] ?? '']),
  );
}

/** What was written in each language; a blank one is left out, so the document prints the name there. */
function namesInput(values: FormValues): LanguageNames {
  return Object.fromEntries(
    DOCUMENT_LANGUAGES.map((language) => [
      language,
      String(values[nameField(language)] ?? '').trim(),
    ]).filter(([, name]) => name !== ''),
  );
}

/** The fields every tax has; the family decides which of rate, amount, threshold and "enters the VAT base" apply. */
function valueFields(
  visible: (field: FormField, families: readonly TaxFamily[]) => FormField,
): FormField[] {
  return [
    visible(
      {
        id: 'rate',
        label: 'fiscal.taxes.rate',
        kind: 'decimal',
        required: true,
        pattern: RATE,
        hint: 'fiscal.taxes.rate_hint',
      },
      withRate,
    ),
    visible(
      {
        id: 'amount',
        label: 'fiscal.taxes.amount',
        kind: 'decimal',
        required: true,
        pattern: AMOUNT,
      },
      ['stamp'],
    ),
    visible(
      {
        id: 'threshold',
        label: 'fiscal.taxes.threshold',
        kind: 'decimal',
        required: true,
        pattern: AMOUNT,
        hint: 'fiscal.taxes.threshold_hint',
      },
      ['withholding'],
    ),
    visible(
      { id: 'entersVatBase', label: 'fiscal.taxes.enters_vat_base', kind: 'checkbox', span: 2 },
      ['levy'],
    ),
  ];
}

const commonFields: FormField[] = [
  { id: 'name', label: 'fiscal.taxes.name', kind: 'text', required: true, maxLength: TAX_NAME_MAX },
  {
    id: 'sortOrder',
    label: 'fiscal.taxes.sort_order',
    kind: 'number',
    required: true,
    min: 0,
    defaultValue: 0,
  },
  {
    id: 'exemptionMention',
    label: 'fiscal.taxes.exemption_mention',
    kind: 'textarea',
    maxLength: 500,
    span: 2,
  },
  { id: 'isDefault', label: 'fiscal.taxes.is_default', kind: 'checkbox' },
];

/** Adding a tax: the family is chosen, and the value fields follow it. */
export const TAX_CREATE_FORM: FormDescriptor = {
  id: 'tax-component-create',
  sections: [
    {
      id: 'identity',
      title: 'fiscal.taxes.section_identity',
      fields: [
        {
          id: 'code',
          label: 'fiscal.taxes.code',
          kind: 'text',
          required: true,
          pattern: '[A-Z][A-Z0-9_]{0,31}',
          hint: 'fiscal.taxes.code_hint',
        },
        {
          id: 'family',
          label: 'fiscal.taxes.family',
          kind: 'select',
          required: true,
          defaultValue: 'vat',
          options: TAX_FAMILIES.map((family) => ({
            value: family,
            label: `fiscal.families.${family}`,
          })),
        },
        ...commonFields,
      ],
    },
    namesSection(TAX_NAME_MAX),
    {
      id: 'value',
      title: 'fiscal.taxes.section_value',
      fields: valueFields((field, families) => ({
        ...field,
        visibleWhen: { field: 'family', oneOf: [...families] },
      })),
    },
  ],
};

/** Revising a tax: its code and family are fixed, so only the value fields of that family are offered. */
export function taxReviseForm(family: TaxFamily): FormDescriptor {
  return {
    id: 'tax-component-revise',
    sections: [
      {
        id: 'identity',
        title: 'fiscal.taxes.section_identity',
        fields: [
          ...commonFields,
          {
            id: 'isActive',
            label: 'fiscal.taxes.is_active',
            kind: 'checkbox',
            hint: 'fiscal.taxes.is_active_hint',
          },
        ],
      },
      namesSection(TAX_NAME_MAX),
      {
        id: 'value',
        title: 'fiscal.taxes.section_value',
        fields: valueFields((field) => field).filter((field) =>
          field.id === 'amount'
            ? family === 'stamp'
            : field.id === 'threshold'
              ? family === 'withholding'
              : field.id === 'entersVatBase'
                ? family === 'levy'
                : withRate.includes(family),
        ),
      },
    ],
  };
}

export function taxFormValues(row: TaxComponentRow): FormValues {
  return {
    name: row.name,
    sortOrder: row.sortOrder,
    exemptionMention: row.exemptionMention ?? '',
    isDefault: row.isDefault,
    isActive: row.isActive,
    rate: row.rate ?? '',
    amount: row.amount ?? '',
    threshold: row.threshold ?? '',
    entersVatBase: row.entersVatBase,
    ...namesValues(row.names),
  };
}

const text = (value: FieldValue | undefined): string | null =>
  typeof value === 'string' && value.trim() !== '' ? value.trim() : null;

/** What the API receives: a field that does not apply to the family is sent as null. */
export function taxInput(
  values: FormValues,
  fixed?: Pick<TaxComponentRow, 'code' | 'family'>,
): TaxComponentInput {
  return {
    code: fixed?.code ?? String(values['code'] ?? ''),
    family: fixed?.family ?? (values['family'] as TaxFamily),
    name: String(values['name'] ?? ''),
    rate: text(values['rate']),
    amount: text(values['amount']),
    threshold: text(values['threshold']),
    entersVatBase: values['entersVatBase'] === true,
    isDefault: values['isDefault'] === true,
    isActive: values['isActive'] !== false,
    exemptionMention: text(values['exemptionMention']),
    sortOrder: Number(values['sortOrder'] ?? 0),
    names: namesInput(values),
  };
}

// Widths that match what each column holds (row 73's balance pass, finding 10): a code, a rate and two yes/no
// columns asked for a name's room and the table was cut in the settings pane.
/** Whether a tax or a unit is still offered: a reference table's natural facet (row 197). */
function activeFilter<Row extends { isActive: boolean }>(label: string): ListFilter<Row> {
  return {
    id: 'status',
    label,
    // « Active », for a tax as for a unit.
    anyLabel: 'list.filter_any_feminine',
    value: (row) => (row.isActive ? 'active' : 'inactive'),
    options: ['active', 'inactive'].map((status) => ({
      value: status,
      label: `fiscal.statuses.${status}`,
    })),
  };
}

export const TAX_LIST: ListDescriptor<TaxComponentRow> = {
  id: 'fiscal-taxes',
  rowId: (row) => row.id,
  pageSizes: [25, 50, 100],
  columns: [
    {
      id: 'code',
      width: 110,
      label: 'fiscal.taxes.code',
      value: (row) => row.code,
      sortable: true,
      filterable: true,
      hideable: false,
    },
    {
      id: 'name',
      width: 230,
      label: 'fiscal.taxes.name',
      value: (row) => row.name,
      sortable: true,
      filterable: true,
    },
    {
      id: 'family',
      width: 130,
      label: 'fiscal.taxes.family',
      value: (row) => row.family,
      sortable: true,
    },
    {
      id: 'value',
      priority: 'high',
      width: 110,
      label: 'fiscal.taxes.value',
      value: (row) => row.rate ?? row.amount,
      align: 'end',
    },
    {
      id: 'threshold',
      width: 120,
      label: 'fiscal.taxes.threshold',
      value: (row) => row.threshold,
      align: 'end',
      defaultHidden: true,
    },
    {
      id: 'isDefault',
      width: 110,
      label: 'fiscal.taxes.is_default',
      value: (row) => (row.isDefault ? 1 : 0),
      sortable: true,
    },
    {
      id: 'isActive',
      label: 'fiscal.taxes.is_active',
      value: (row) => (row.isActive ? 1 : 0),
      sortable: true,
    },
  ],
  filters: [
    {
      id: 'family',
      label: 'fiscal.taxes.family',
      anyLabel: 'list.filter_any_feminine',
      multiple: true,
      value: (row) => row.family,
      options: TAX_FAMILIES.map((family) => ({
        value: family,
        label: `fiscal.families.${family}`,
      })),
    },
    activeFilter('fiscal.taxes.is_active'),
  ],
};

export const REGIME_LIST: ListDescriptor<CustomerTaxRegimeRow> = {
  id: 'fiscal-regimes',
  rowId: (row) => row.code,
  pageSizes: [25],
  columns: [
    { id: 'label', label: 'fiscal.regimes.label', value: (row) => row.label, hideable: false },
    {
      id: 'excluded',
      label: 'fiscal.regimes.excluded',
      value: (row) => row.excludedFamilies.join(', '),
    },
    { id: 'mention', label: 'fiscal.regimes.mention', value: (row) => (row.hasMention ? 1 : 0) },
  ],
};

export const UNIT_LIST: ListDescriptor<UnitRow> = {
  id: 'fiscal-units',
  rowId: (row) => row.id,
  pageSizes: [25, 50, 100],
  columns: [
    {
      id: 'code',
      label: 'fiscal.units.code',
      value: (row) => row.code,
      sortable: true,
      filterable: true,
      hideable: false,
    },
    {
      id: 'name',
      label: 'fiscal.units.name',
      value: (row) => row.name,
      sortable: true,
      filterable: true,
    },
    {
      id: 'decimals',
      label: 'fiscal.units.decimals',
      value: (row) => row.decimals,
      sortable: true,
      align: 'end',
    },
    {
      id: 'isActive',
      label: 'fiscal.units.is_active',
      value: (row) => (row.isActive ? 1 : 0),
      sortable: true,
    },
  ],
  filters: [activeFilter('fiscal.units.is_active')],
};

const unitCommon: FormField[] = [
  {
    id: 'name',
    label: 'fiscal.units.name',
    kind: 'text',
    required: true,
    maxLength: UNIT_NAME_MAX,
  },
  {
    id: 'decimals',
    label: 'fiscal.units.decimals',
    kind: 'number',
    required: true,
    min: 0,
    max: 3,
    defaultValue: 0,
    hint: 'fiscal.units.decimals_hint',
  },
  {
    id: 'sortOrder',
    label: 'fiscal.units.sort_order',
    kind: 'number',
    required: true,
    min: 0,
    defaultValue: 0,
  },
];

export const UNIT_CREATE_FORM: FormDescriptor = {
  id: 'unit-create',
  sections: [
    {
      id: 'unit',
      title: 'fiscal.units.section',
      fields: [
        {
          id: 'code',
          label: 'fiscal.units.code',
          kind: 'text',
          required: true,
          pattern: '[A-Z0-9]{2,3}',
          hint: 'fiscal.units.code_hint',
        },
        ...unitCommon,
      ],
    },
    namesSection(UNIT_NAME_MAX),
  ],
};

export const UNIT_REVISE_FORM: FormDescriptor = {
  id: 'unit-revise',
  sections: [
    {
      id: 'unit',
      title: 'fiscal.units.section',
      fields: [
        ...unitCommon,
        { id: 'isActive', label: 'fiscal.units.is_active', kind: 'checkbox' },
      ],
    },
    namesSection(UNIT_NAME_MAX),
  ],
};

export function unitFormValues(row: UnitRow): FormValues {
  return {
    name: row.name,
    decimals: row.decimals,
    sortOrder: row.sortOrder,
    isActive: row.isActive,
    ...namesValues(row.names),
  };
}

export function unitInput(values: FormValues, code?: string): UnitInput {
  return {
    code: code ?? String(values['code'] ?? ''),
    name: String(values['name'] ?? ''),
    decimals: Number(values['decimals'] ?? 0),
    sortOrder: Number(values['sortOrder'] ?? 0),
    isActive: values['isActive'] !== false,
    names: namesInput(values),
  };
}
