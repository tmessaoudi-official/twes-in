// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  customFieldInput,
  customFieldValues,
  customFormFields,
  customListColumns,
} from '../shared/custom-fields/custom-fields';
import type { CustomFieldDefinition } from '../shared/custom-fields/custom-fields-types';
import { withCustomFields } from '../shared/form/form-builder';
import type {
  FieldValue,
  FormDescriptor,
  FormField,
  FormSection,
  FormValues,
} from '../shared/form/form-types';
import type { ListDescriptor, ListQuery } from '../shared/list/list-types';
import { withCustomColumns } from '../shared/list/list-view';
import {
  CUSTOMER_KINDS,
  type ContactInput,
  type ContactRow,
  type CustomerAddress,
  type CustomerGroupInput,
  type CustomerGroupRow,
  type CustomerInput,
  type CustomerKind,
  type CustomerOptions,
  type CustomerRow,
  type CustomerSearch,
  type CustomerSortKey,
} from './customers-types';

const FIELDS = 'customers.fields';
const IDENTIFIER_PREFIX = 'identifier__';
const TAX_PREFIX = 'tax__';
const PHONE_PATTERN = '\\+?[0-9 ().\\-]{3,40}';

/** A customer as the list shows it: with the name of its group rather than the group's id. */
export type CustomerListRow = CustomerRow & { groupName: string | null };

export function customerListRows(
  rows: readonly CustomerRow[],
  groups: readonly CustomerGroupRow[],
): CustomerListRow[] {
  const names = new Map(groups.map((group) => [group.id, group.name]));
  return rows.map((row) => ({
    ...row,
    groupName: row.customerGroupId === null ? null : (names.get(row.customerGroupId) ?? null),
  }));
}

export const CUSTOMERS_LIST: ListDescriptor<CustomerListRow> = {
  id: 'customers',
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
    { id: 'kind', label: `${FIELDS}.kind`, value: (row) => row.kind, sortable: true, width: 140 },
    {
      id: 'group',
      label: `${FIELDS}.customerGroupId`,
      value: (row) => row.groupName ?? '',
      sortable: true,
      filterable: true,
    },
    {
      id: 'city',
      label: `${FIELDS}.billingCity`,
      value: (row) => row.billingAddress.city ?? '',
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
      id: 'status',
      label: `${FIELDS}.isActive`,
      value: (row) => (row.isActive ? 'active' : 'inactive'),
      sortable: true,
      width: 120,
    },
  ],
  filters: [
    {
      id: 'kind',
      label: `${FIELDS}.kind`,
      value: (row) => row.kind,
      options: CUSTOMER_KINDS.map((kind) => ({ value: kind, label: `customers.kinds.${kind}` })),
    },
    {
      id: 'status',
      label: `${FIELDS}.isActive`,
      value: (row) => (row.isActive ? 'active' : 'inactive'),
      options: ['active', 'inactive'].map((status) => ({
        value: status,
        label: `customers.statuses.${status}`,
      })),
    },
  ],
};

/** The column a person sorts by, as the API names what it sorts customers by. */
const SORT_KEYS: Readonly<Record<string, CustomerSortKey>> = {
  number: 'number',
  name: 'name',
  kind: 'kind',
  group: 'customerGroup',
  city: 'city',
  status: 'isActive',
};

/**
 * The customers list with a hidden column per active custom field of the company. The API pages it, so a column is
 * sortable only when the API sorts customers by it (docs/SPEC.md § 7, lists at scale).
 */
export function customersList(
  fields: readonly CustomFieldDefinition[],
): ListDescriptor<CustomerListRow> {
  const list = withCustomColumns(CUSTOMERS_LIST, customListColumns<CustomerListRow>(fields));
  return {
    ...list,
    columns: list.columns.map((column) => ({
      ...column,
      sortable: column.sortable === true && column.id in SORT_KEYS,
    })),
  };
}

/** What the API is asked for the page of customers the list shows. */
export function customerSearch(query: ListQuery): CustomerSearch {
  const kind = CUSTOMER_KINDS.find((known) => known === query.filters['kind']) ?? null;
  const status = query.filters['status'];
  const key = query.sort === null ? undefined : SORT_KEYS[query.sort.column];
  return {
    page: query.pageIndex + 1,
    itemsPerPage: query.pageSize,
    q: query.query,
    kind,
    isActive: status === 'active' ? true : status === 'inactive' ? false : null,
    order:
      query.sort === null || key === undefined ? null : { key, direction: query.sort.direction },
  };
}

const section = (id: string, fields: FormField[]): FormSection => ({
  id,
  title: `customers.sections.${id}`,
  fields,
});

const addressFields = (prefix: 'billing' | 'shipping'): FormField[] => [
  {
    id: `${prefix}AddressLine1`,
    label: `${FIELDS}.addressLine1`,
    kind: 'text',
    maxLength: 200,
    span: 2,
    ...(prefix === 'shipping' ? { hint: 'customers.form.shipping_hint' } : {}),
  },
  {
    id: `${prefix}AddressLine2`,
    label: `${FIELDS}.addressLine2`,
    kind: 'text',
    maxLength: 200,
    span: 2,
  },
  { id: `${prefix}PostalCode`, label: `${FIELDS}.postalCode`, kind: 'text', maxLength: 20 },
  { id: `${prefix}City`, label: `${FIELDS}.city`, kind: 'text', maxLength: 120 },
  {
    id: `${prefix}CountryCode`,
    label: `${FIELDS}.countryCode`,
    kind: 'text',
    maxLength: 2,
    pattern: '[A-Za-z]{2}',
    hint: 'customers.form.country_hint',
  },
];

/**
 * The customer form, from what the company's preset and fiscal setup offer: its registration numbers (shown for a
 * business, never required here because only the API knows whether the customer is billed at home), its regimes
 * and one box per active tax a new line for the customer starts with, then the company's own custom fields, joined
 * through withCustomFields. The API checks everything again.
 */
export function customerForm(
  options: CustomerOptions,
  groups: readonly CustomerGroupRow[],
  fields: readonly CustomFieldDefinition[] = [],
): FormDescriptor {
  const identifiers: FormField[] = options.identifiers.map((identifier) => ({
    id: IDENTIFIER_PREFIX + identifier.key,
    label: identifier.label,
    kind: 'text',
    maxLength: 64,
    pattern: identifier.pattern,
    visibleWhen: { field: 'kind', oneOf: ['company'] },
    ...(identifier.requiredForBusiness ? { hint: 'customers.form.identifier_hint' } : {}),
  }));

  const descriptor: FormDescriptor = {
    id: 'customer',
    sections: [
      section('identity', [
        {
          id: 'number',
          label: `${FIELDS}.number`,
          kind: 'text',
          required: true,
          maxLength: 32,
          pattern: '[A-Za-z0-9][A-Za-z0-9._/\\-]{0,31}',
          hint: 'customers.form.number_hint',
        },
        {
          id: 'kind',
          label: `${FIELDS}.kind`,
          kind: 'select',
          required: true,
          options: CUSTOMER_KINDS.map((kind) => ({
            value: kind,
            label: `customers.kinds.${kind}`,
          })),
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
        {
          id: 'customerGroupId',
          label: `${FIELDS}.customerGroupId`,
          kind: 'select',
          options: [
            { value: '', label: 'customers.form.no_group' },
            ...groups.map((group) => ({ value: group.id, label: group.name })),
          ],
        },
        {
          id: 'isActive',
          label: `${FIELDS}.isActive`,
          kind: 'checkbox',
          hint: 'customers.form.active_hint',
        },
      ]),
      ...(identifiers.length > 0 ? [section('identifiers', identifiers)] : []),
      section('contact', [
        { id: 'email', label: `${FIELDS}.email`, kind: 'email', maxLength: 254 },
        {
          id: 'phone',
          label: `${FIELDS}.phone`,
          kind: 'tel',
          maxLength: 40,
          pattern: PHONE_PATTERN,
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
      section('billing', addressFields('billing')),
      section('shipping', addressFields('shipping')),
      section('terms', [
        {
          id: 'taxRegime',
          label: `${FIELDS}.taxRegime`,
          kind: 'select',
          required: true,
          options: options.regimes.map((regime) => ({ value: regime.code, label: regime.label })),
        },
        {
          id: 'defaultDiscountRate',
          label: `${FIELDS}.defaultDiscountRate`,
          kind: 'decimal',
          maxLength: 7,
          pattern: '[0-9]{1,3}([.][0-9]{1,3})?',
          hint: 'customers.form.discount_hint',
        },
        ...options.taxes.map((tax): FormField => ({
          id: TAX_PREFIX + tax.id,
          label: tax.name,
          kind: 'checkbox',
        })),
      ]),
      section('notes', [
        {
          id: 'notes',
          label: `${FIELDS}.notes`,
          kind: 'textarea',
          maxLength: 5000,
          span: 2,
          hint: 'customers.form.notes_hint',
        },
      ]),
    ],
  };
  const custom = customFormFields(fields);
  return custom.length === 0
    ? descriptor
    : withCustomFields(
        { ...descriptor, sections: [...descriptor.sections, section('custom', [])] },
        'custom',
        custom,
      );
}

/** Each field at the customer's value; a new customer is a business in the company's country under the first regime. */
export function customerValues(
  row: CustomerRow | null,
  options: CustomerOptions,
  fields: readonly CustomFieldDefinition[] = [],
): FormValues {
  const values: FormValues = {
    number: row?.number ?? '',
    kind: row?.kind ?? 'company',
    name: row?.name ?? '',
    legalName: row?.legalName ?? '',
    customerGroupId: row?.customerGroupId ?? '',
    isActive: row?.isActive ?? true,
    email: row?.email ?? '',
    phone: row?.phone ?? '',
    website: row?.website ?? '',
    taxRegime: row?.taxRegime ?? options.regimes[0]?.code ?? '',
    defaultDiscountRate: row?.defaultDiscountRate ?? '',
    notes: row?.notes ?? '',
    ...addressValues(
      'billing',
      row?.billingAddress ?? null,
      row === null ? options.countryCode : '',
    ),
    ...addressValues('shipping', row?.shippingAddress ?? null, ''),
  };
  for (const identifier of options.identifiers) {
    values[IDENTIFIER_PREFIX + identifier.key] = row?.identifiers[identifier.key] ?? '';
  }
  for (const tax of options.taxes) {
    values[TAX_PREFIX + tax.id] = row?.defaultTaxComponentIds.includes(tax.id) ?? false;
  }
  return { ...values, ...customFieldValues(fields, row?.customFields ?? {}) };
}

/** The form's values as the API takes them: trimmed, an empty field as no value, an individual without identifiers. */
export function customerInput(
  values: FormValues,
  options: CustomerOptions,
  fields: readonly CustomFieldDefinition[] = [],
): CustomerInput {
  const kind = (CUSTOMER_KINDS as readonly string[]).includes(String(values['kind']))
    ? (values['kind'] as CustomerKind)
    : 'company';
  const identifiers: Record<string, string> = {};
  if (kind === 'company') {
    for (const identifier of options.identifiers) {
      const value = text(values[IDENTIFIER_PREFIX + identifier.key]);
      if (value !== null) {
        identifiers[identifier.key] = value;
      }
    }
  }
  const shipping = address(values, 'shipping');

  return {
    number: String(values['number'] ?? '').trim(),
    kind,
    customerGroupId: text(values['customerGroupId']),
    taxRegime: String(values['taxRegime'] ?? ''),
    name: String(values['name'] ?? '').trim(),
    legalName: text(values['legalName']),
    identifiers,
    email: text(values['email']),
    phone: text(values['phone']),
    website: text(values['website']),
    billingAddress: address(values, 'billing'),
    shippingAddress: Object.values(shipping).every((part) => part === null) ? null : shipping,
    defaultTaxComponentIds: options.taxes
      .filter((tax) => values[TAX_PREFIX + tax.id] === true)
      .map((tax) => tax.id),
    defaultDiscountRate: text(values['defaultDiscountRate']),
    notes: text(values['notes']),
    isActive: values['isActive'] === true,
    customFields: customFieldInput(fields, values),
  };
}

const GROUP_FIELDS = 'customers.groups.fields';

export const GROUPS_LIST: ListDescriptor<CustomerGroupRow> = {
  id: 'customer-groups',
  rowId: (row) => row.id,
  pageSizes: [25, 50, 100],
  defaultSort: { column: 'name', direction: 'asc' },
  columns: [
    {
      id: 'name',
      label: `${GROUP_FIELDS}.name`,
      value: (row) => row.name,
      sortable: true,
      filterable: true,
      hideable: false,
    },
    {
      id: 'description',
      label: `${GROUP_FIELDS}.description`,
      value: (row) => row.description ?? '',
      filterable: true,
    },
    {
      id: 'customerCount',
      label: `${GROUP_FIELDS}.customerCount`,
      value: (row) => row.customerCount,
      sortable: true,
      align: 'end',
      width: 140,
    },
  ],
};

export const GROUP_FORM: FormDescriptor = {
  id: 'customer-group',
  sections: [
    {
      id: 'group',
      title: 'customers.groups.section',
      fields: [
        { id: 'name', label: `${GROUP_FIELDS}.name`, kind: 'text', required: true, maxLength: 120 },
        {
          id: 'description',
          label: `${GROUP_FIELDS}.description`,
          kind: 'textarea',
          maxLength: 500,
          span: 2,
        },
      ],
    },
  ],
};

export function groupValues(row: CustomerGroupRow | null): FormValues {
  return { name: row?.name ?? '', description: row?.description ?? '' };
}

export function groupInput(values: FormValues): CustomerGroupInput {
  return { name: String(values['name'] ?? '').trim(), description: text(values['description']) };
}

const CONTACT_FIELDS = 'customers.contacts.fields';

export const CONTACTS_LIST: ListDescriptor<ContactRow> = {
  id: 'customer-contacts',
  rowId: (row) => row.id,
  pageSizes: [10, 25],
  columns: [
    {
      id: 'name',
      label: `${CONTACT_FIELDS}.name`,
      value: (row) => [row.firstName, row.lastName].filter((part) => part !== null).join(' '),
      hideable: false,
    },
    { id: 'role', label: `${CONTACT_FIELDS}.role`, value: (row) => row.role ?? '' },
    { id: 'email', label: `${CONTACT_FIELDS}.email`, value: (row) => row.email ?? '' },
    { id: 'phone', label: `${CONTACT_FIELDS}.phone`, value: (row) => row.phone ?? '' },
    {
      id: 'isPrimary',
      label: `${CONTACT_FIELDS}.isPrimary`,
      value: (row) => (row.isPrimary ? 1 : 0),
      width: 120,
    },
  ],
};

export const CONTACT_FORM: FormDescriptor = {
  id: 'customer-contact',
  sections: [
    {
      id: 'contact',
      title: 'customers.contacts.section',
      fields: [
        { id: 'firstName', label: `${CONTACT_FIELDS}.firstName`, kind: 'text', maxLength: 100 },
        {
          id: 'lastName',
          label: `${CONTACT_FIELDS}.lastName`,
          kind: 'text',
          maxLength: 100,
          hint: 'customers.contacts.name_hint',
        },
        { id: 'role', label: `${CONTACT_FIELDS}.role`, kind: 'text', maxLength: 100 },
        { id: 'email', label: `${CONTACT_FIELDS}.email`, kind: 'email', maxLength: 254 },
        {
          id: 'phone',
          label: `${CONTACT_FIELDS}.phone`,
          kind: 'tel',
          maxLength: 40,
          pattern: PHONE_PATTERN,
        },
        { id: 'isPrimary', label: `${CONTACT_FIELDS}.isPrimary`, kind: 'checkbox' },
      ],
    },
  ],
};

export function contactValues(row: ContactRow | null): FormValues {
  return {
    firstName: row?.firstName ?? '',
    lastName: row?.lastName ?? '',
    email: row?.email ?? '',
    phone: row?.phone ?? '',
    role: row?.role ?? '',
    isPrimary: row?.isPrimary ?? false,
  };
}

export function contactInput(values: FormValues): ContactInput {
  return {
    firstName: text(values['firstName']),
    lastName: text(values['lastName']),
    email: text(values['email']),
    phone: text(values['phone']),
    role: text(values['role']),
    isPrimary: values['isPrimary'] === true,
  };
}

function addressValues(
  prefix: 'billing' | 'shipping',
  value: CustomerAddress | null,
  countryCode: string,
): FormValues {
  return {
    [`${prefix}AddressLine1`]: value?.line1 ?? '',
    [`${prefix}AddressLine2`]: value?.line2 ?? '',
    [`${prefix}PostalCode`]: value?.postalCode ?? '',
    [`${prefix}City`]: value?.city ?? '',
    [`${prefix}CountryCode`]: value?.countryCode ?? countryCode,
  };
}

function address(values: FormValues, prefix: 'billing' | 'shipping'): CustomerAddress {
  const country = text(values[`${prefix}CountryCode`]);
  return {
    line1: text(values[`${prefix}AddressLine1`]),
    line2: text(values[`${prefix}AddressLine2`]),
    postalCode: text(values[`${prefix}PostalCode`]),
    city: text(values[`${prefix}City`]),
    countryCode: country === null ? null : country.toUpperCase(),
  };
}

function text(value: FieldValue | undefined): string | null {
  const trimmed = String(value ?? '').trim();
  return trimmed === '' ? null : trimmed;
}
