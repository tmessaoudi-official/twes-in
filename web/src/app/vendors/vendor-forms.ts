// SPDX-License-Identifier: AGPL-3.0-or-later

import type {
  FieldValue,
  FormDescriptor,
  FormField,
  FormSection,
  FormValues,
} from '../shared/form/form-types';
import type { ListDescriptor } from '../shared/list/list-types';
import type { VendorInput, VendorOptions, VendorRow } from './vendors-types';

const FIELDS = 'vendors.fields';
const IDENTIFIER_PREFIX = 'identifier__';

export const VENDORS_LIST: ListDescriptor<VendorRow> = {
  id: 'vendors',
  rowId: (row) => row.id,
  pageSizes: [25, 50, 100],
  defaultSort: { column: 'number', direction: 'asc' },
  columns: [
    {
      id: 'number',
      label: `${FIELDS}.number`,
      value: (row) => row.number,
      sortable: true,
      filterable: true,
      hideable: false,
      width: 140,
    },
    {
      id: 'name',
      label: `${FIELDS}.name`,
      value: (row) => row.name,
      sortable: true,
      filterable: true,
      hideable: false,
    },
    {
      id: 'city',
      label: `${FIELDS}.city`,
      value: (row) => row.address.city ?? '',
      sortable: true,
      filterable: true,
    },
    {
      id: 'email',
      label: `${FIELDS}.email`,
      value: (row) => row.email ?? '',
      filterable: true,
      defaultHidden: true,
    },
    {
      id: 'paymentTermsDays',
      label: `${FIELDS}.paymentTermsDays`,
      value: (row) => row.paymentTermsDays ?? '',
      sortable: true,
      align: 'end',
      width: 160,
    },
    {
      id: 'status',
      label: `${FIELDS}.isActive`,
      value: (row) => (row.isActive ? 'active' : 'inactive'),
      sortable: true,
      width: 120,
    },
  ],
  filters: [
    {
      id: 'status',
      label: `${FIELDS}.isActive`,
      value: (row) => (row.isActive ? 'active' : 'inactive'),
      options: ['active', 'inactive'].map((status) => ({
        value: status,
        label: `vendors.statuses.${status}`,
      })),
    },
  ],
};

const section = (id: string, fields: FormField[]): FormSection => ({
  id,
  title: `vendors.sections.${id}`,
  fields,
});

/**
 * The vendor form, from what the company's preset offers: its registration numbers, none of them required of a
 * vendor, each in its shape. The API checks everything again.
 */
export function vendorForm(options: VendorOptions): FormDescriptor {
  const identifiers: FormField[] = options.identifiers.map((identifier) => ({
    id: IDENTIFIER_PREFIX + identifier.key,
    label: identifier.label,
    kind: 'text',
    maxLength: 64,
    pattern: identifier.pattern,
  }));

  return {
    id: 'vendor',
    sections: [
      section('identity', [
        {
          id: 'number',
          label: `${FIELDS}.number`,
          kind: 'text',
          required: true,
          maxLength: 32,
          pattern: '[A-Za-z0-9][A-Za-z0-9._/\\-]{0,31}',
          hint: 'vendors.form.number_hint',
        },
        {
          id: 'isActive',
          label: `${FIELDS}.isActive`,
          kind: 'checkbox',
          hint: 'vendors.form.active_hint',
        },
        {
          id: 'name',
          label: `${FIELDS}.name`,
          kind: 'text',
          required: true,
          maxLength: 200,
          span: 2,
        },
        { id: 'legalName', label: `${FIELDS}.legalName`, kind: 'text', maxLength: 200, span: 2 },
      ]),
      ...(identifiers.length > 0 ? [section('identifiers', identifiers)] : []),
      section('contact', [
        { id: 'email', label: `${FIELDS}.email`, kind: 'email', maxLength: 254 },
        {
          id: 'phone',
          label: `${FIELDS}.phone`,
          kind: 'tel',
          maxLength: 40,
          pattern: '\\+?[0-9 ().\\-]{3,40}',
        },
        {
          id: 'website',
          label: `${FIELDS}.website`,
          kind: 'text',
          maxLength: 200,
          pattern: 'https?://\\S+',
          span: 2,
        },
      ]),
      section('address', [
        {
          id: 'addressLine1',
          label: `${FIELDS}.addressLine1`,
          kind: 'text',
          maxLength: 200,
          span: 2,
        },
        {
          id: 'addressLine2',
          label: `${FIELDS}.addressLine2`,
          kind: 'text',
          maxLength: 200,
          span: 2,
        },
        { id: 'postalCode', label: `${FIELDS}.postalCode`, kind: 'text', maxLength: 20 },
        { id: 'city', label: `${FIELDS}.city`, kind: 'text', maxLength: 120 },
        {
          id: 'countryCode',
          label: `${FIELDS}.countryCode`,
          kind: 'text',
          maxLength: 2,
          pattern: '[A-Za-z]{2}',
          hint: 'vendors.form.country_hint',
        },
      ]),
      section('payment', [
        {
          id: 'iban',
          label: `${FIELDS}.iban`,
          kind: 'text',
          maxLength: 42,
          pattern: '[A-Za-z]{2}[0-9]{2}[A-Za-z0-9 ]{8,38}',
          span: 2,
          hint: 'vendors.form.iban_hint',
        },
        {
          id: 'bic',
          label: `${FIELDS}.bic`,
          kind: 'text',
          maxLength: 11,
          pattern: '[A-Za-z]{6}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?',
        },
        {
          id: 'paymentTermsDays',
          label: `${FIELDS}.paymentTermsDays`,
          kind: 'text',
          maxLength: 3,
          pattern: '[0-9]{1,3}',
          hint: 'vendors.form.terms_hint',
        },
      ]),
      section('notes', [
        {
          id: 'notes',
          label: `${FIELDS}.notes`,
          kind: 'textarea',
          maxLength: 5000,
          span: 2,
          hint: 'vendors.form.notes_hint',
        },
      ]),
    ],
  };
}

/** Each field at the vendor's value; a new vendor is active and in the company's country. */
export function vendorValues(row: VendorRow | null, options: VendorOptions): FormValues {
  const values: FormValues = {
    number: row?.number ?? '',
    name: row?.name ?? '',
    legalName: row?.legalName ?? '',
    isActive: row?.isActive ?? true,
    email: row?.email ?? '',
    phone: row?.phone ?? '',
    website: row?.website ?? '',
    addressLine1: row?.address.line1 ?? '',
    addressLine2: row?.address.line2 ?? '',
    postalCode: row?.address.postalCode ?? '',
    city: row?.address.city ?? '',
    countryCode: row === null ? options.countryCode : (row.address.countryCode ?? ''),
    iban: row?.iban ?? '',
    bic: row?.bic ?? '',
    paymentTermsDays:
      row === null || row.paymentTermsDays === null ? '' : String(row.paymentTermsDays),
    notes: row?.notes ?? '',
  };
  for (const identifier of options.identifiers) {
    values[IDENTIFIER_PREFIX + identifier.key] = row?.identifiers[identifier.key] ?? '';
  }
  return values;
}

/** The form's values as the API takes them: trimmed, an empty field as no value, the terms as a number of days. */
export function vendorInput(values: FormValues, options: VendorOptions): VendorInput {
  const identifiers: Record<string, string> = {};
  for (const identifier of options.identifiers) {
    const value = text(values[IDENTIFIER_PREFIX + identifier.key]);
    if (value !== null) {
      identifiers[identifier.key] = value;
    }
  }
  const country = text(values['countryCode']);
  const days = text(values['paymentTermsDays']);

  return {
    number: String(values['number'] ?? '').trim(),
    name: String(values['name'] ?? '').trim(),
    legalName: text(values['legalName']),
    identifiers,
    email: text(values['email']),
    phone: text(values['phone']),
    website: text(values['website']),
    address: {
      line1: text(values['addressLine1']),
      line2: text(values['addressLine2']),
      postalCode: text(values['postalCode']),
      city: text(values['city']),
      countryCode: country === null ? null : country.toUpperCase(),
    },
    iban: text(values['iban']),
    bic: text(values['bic']),
    paymentTermsDays: days === null ? null : Number(days),
    notes: text(values['notes']),
    isActive: values['isActive'] === true,
  };
}

function text(value: FieldValue | undefined): string | null {
  const trimmed = String(value ?? '').trim();
  return trimmed === '' ? null : trimmed;
}
