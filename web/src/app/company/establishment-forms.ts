// SPDX-License-Identifier: AGPL-3.0-or-later

import type { FieldValue, FormDescriptor, FormValues } from '../shared/form/form-types';
import type { ListDescriptor } from '../shared/list/list-types';
import {
  RESET_PERIODS,
  type EstablishmentInput,
  type EstablishmentRow,
  type NumberingChanges,
  type NumberingSeriesRow,
} from './company-types';
import { NUMBER_FORMAT_MAX_LENGTH } from './number-format';

const FIELDS = 'company.establishments.fields';

export const ESTABLISHMENT_LIST: ListDescriptor<EstablishmentRow> = {
  id: 'company-establishments',
  rowId: (row) => row.id,
  pageSizes: [25, 50, 100],
  columns: [
    {
      id: 'code',
      label: `${FIELDS}.code`,
      value: (row) => row.code,
      sortable: true,
      filterable: true,
      hideable: false,
    },
    {
      id: 'name',
      label: `${FIELDS}.name`,
      value: (row) => row.name,
      sortable: true,
      filterable: true,
    },
    {
      id: 'city',
      label: `${FIELDS}.city`,
      value: (row) => row.city ?? '',
      sortable: true,
      filterable: true,
    },
    {
      id: 'isDefault',
      label: `${FIELDS}.isDefault`,
      value: (row) => (row.isDefault ? 1 : 0),
      sortable: true,
    },
  ],
};

/**
 * The code is checked against the shape the company's preset gives it, once the API has said what that is, and
 * shown without being editable once numbered documents carry it.
 */
export function establishmentForm(codePattern: string, codeLocked = false): FormDescriptor {
  return {
    id: 'establishment',
    sections: [
      {
        id: 'establishment',
        title: 'company.establishments.section',
        fields: [
          {
            id: 'code',
            label: `${FIELDS}.code`,
            kind: 'text',
            required: true,
            maxLength: 16,
            ...(codePattern === '' ? {} : { pattern: codePattern }),
            hint: codeLocked ? `${FIELDS}.code_locked_hint` : `${FIELDS}.code_hint`,
            ...(codeLocked ? { readOnly: true } : {}),
          },
          { id: 'name', label: `${FIELDS}.name`, kind: 'text', required: true, maxLength: 120 },
          {
            id: 'addressLine1',
            label: `${FIELDS}.addressLine1`,
            kind: 'text',
            maxLength: 200,
            span: 2,
            autocomplete: 'address-line1',
          },
          {
            id: 'addressLine2',
            label: `${FIELDS}.addressLine2`,
            kind: 'text',
            maxLength: 200,
            span: 2,
            autocomplete: 'address-line2',
          },
          {
            id: 'postalCode',
            label: `${FIELDS}.postalCode`,
            kind: 'text',
            maxLength: 20,
            autocomplete: 'postal-code',
          },
          {
            id: 'city',
            label: `${FIELDS}.city`,
            kind: 'text',
            maxLength: 120,
            autocomplete: 'address-level2',
          },
          { id: 'phone', label: `${FIELDS}.phone`, kind: 'tel', maxLength: 40 },
          { id: 'email', label: `${FIELDS}.email`, kind: 'email', maxLength: 254 },
          {
            id: 'isDefault',
            label: `${FIELDS}.isDefault`,
            kind: 'checkbox',
            hint: `${FIELDS}.isDefault_hint`,
            span: 2,
          },
        ],
      },
    ],
  };
}

export function establishmentFormValues(row: EstablishmentRow): FormValues {
  return {
    code: row.code,
    name: row.name,
    addressLine1: row.addressLine1 ?? '',
    addressLine2: row.addressLine2 ?? '',
    postalCode: row.postalCode ?? '',
    city: row.city ?? '',
    phone: row.phone ?? '',
    email: row.email ?? '',
    isDefault: row.isDefault,
  };
}

const text = (value: FieldValue | undefined): string | null => {
  const trimmed = String(value ?? '').trim();
  return trimmed === '' ? null : trimmed;
};

export function establishmentInput(values: FormValues): EstablishmentInput {
  return {
    code: String(values['code'] ?? '').trim(),
    name: String(values['name'] ?? '').trim(),
    addressLine1: text(values['addressLine1']),
    addressLine2: text(values['addressLine2']),
    postalCode: text(values['postalCode']),
    city: text(values['city']),
    phone: text(values['phone']),
    email: text(values['email']),
    isDefault: values['isDefault'] === true,
  };
}

const COLUMNS = 'company.numbering.columns';

// The widths are what each column HOLDS, not the 160 px every column takes by default: six of those plus the
// actions want 1056 px where the settings pane offers 968, which is what cut this table (row 73's balance pass,
// design review finding 10, measured 2026-09-20).
export const SERIES_LIST: ListDescriptor<NumberingSeriesRow> = {
  id: 'company-numbering-series',
  rowId: (row) => row.id,
  pageSizes: [25, 50, 100],
  columns: [
    {
      id: 'establishment',
      width: 120,
      label: `${COLUMNS}.establishment`,
      value: (row) => row.establishmentCode,
      sortable: true,
      filterable: true,
      hideable: false,
    },
    {
      id: 'documentType',
      width: 150,
      label: `${COLUMNS}.documentType`,
      value: (row) => row.documentType,
      sortable: true,
      hideable: false,
    },
    { id: 'format', width: 190, label: `${COLUMNS}.format`, value: (row) => row.format },
    {
      id: 'nextNumber',
      width: 120,
      label: `${COLUMNS}.nextNumber`,
      value: (row) => row.nextNumber,
      align: 'end',
    },
    {
      id: 'resetPeriod',
      width: 130,
      label: `${COLUMNS}.resetPeriod`,
      value: (row) => row.resetPeriod,
    },
    { id: 'preview', width: 150, label: `${COLUMNS}.preview`, value: (row) => row.preview },
  ],
};

/** Where the sequence resumes is shown without being editable once documents carry its numbers. */
export function seriesForm(numbered: boolean): FormDescriptor {
  return {
    id: 'numbering-series',
    sections: [
      {
        id: 'series',
        title: 'company.numbering.section',
        fields: [
          {
            id: 'format',
            label: 'company.numbering.fields.format',
            kind: 'text',
            required: true,
            maxLength: NUMBER_FORMAT_MAX_LENGTH,
            hint: 'company.numbering.fields.format_hint',
            span: 2,
          },
          {
            id: 'nextNumber',
            label: 'company.numbering.fields.nextNumber',
            kind: 'number',
            required: true,
            min: 1,
            hint: numbered
              ? 'company.numbering.fields.nextNumber_frozen_hint'
              : 'company.numbering.fields.nextNumber_hint',
            ...(numbered ? { readOnly: true } : {}),
          },
          {
            id: 'resetPeriod',
            label: 'company.numbering.fields.resetPeriod',
            kind: 'select',
            required: true,
            options: RESET_PERIODS.map((period) => ({
              value: period,
              label: `company.numbering.reset.${period}`,
            })),
          },
        ],
      },
    ],
  };
}

export function seriesFormValues(row: NumberingSeriesRow): FormValues {
  return { format: row.format, nextNumber: row.nextNumber, resetPeriod: row.resetPeriod };
}

export function seriesChanges(values: FormValues): NumberingChanges {
  return {
    format: String(values['format'] ?? ''),
    nextNumber: Number(values['nextNumber'] ?? 0),
    resetPeriod: RESET_PERIODS.find((period) => period === values['resetPeriod']) ?? 'yearly',
  };
}
