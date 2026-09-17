// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  contactInput,
  contactValues,
  customerForm,
  customerInput,
  customerSearch,
  customersList,
  customerValues,
  CUSTOMERS_LIST,
  groupInput,
  groupValues,
} from './customer-forms';
import type { CustomerGroupRow, CustomerOptions, CustomerRow } from './customers-types';

const options: CustomerOptions = {
  countryCode: 'TN',
  identifiers: [
    {
      key: 'matricule_fiscal',
      label: 'Matricule fiscal',
      pattern: '^[0-9]{7}[A-Z]/[A-Z]/[A-Z]/[0-9]{3}$',
      requiredForBusiness: true,
    },
  ],
  regimes: [
    { code: 'standard', label: 'Régime normal', excludedFamilies: [] },
    { code: 'exempt', label: 'Exonéré de TVA', excludedFamilies: ['vat'] },
  ],
  taxes: [
    { id: 't-vat', code: 'TVA19', name: 'TVA 19 %', family: 'vat' },
    { id: 't-fodec', code: 'FODEC', name: 'FODEC 1 %', family: 'levy' },
  ],
};

const groups: CustomerGroupRow[] = [
  { id: 'g1', name: 'Grossistes', description: null, customerCount: 2 },
];

const carthage: CustomerRow = {
  id: 'c1',
  number: 'CLI-0001',
  kind: 'company',
  customerGroupId: 'g1',
  taxRegime: 'standard',
  name: 'Carthage Conseil',
  legalName: null,
  identifiers: { matricule_fiscal: '1234567A/B/M/000' },
  email: 'compta@carthage.tn',
  phone: null,
  website: null,
  billingAddress: {
    line1: '12, rue du Lac',
    line2: null,
    postalCode: '1053',
    city: 'Tunis',
    countryCode: 'TN',
  },
  shippingAddress: null,
  defaultTaxComponentIds: ['t-vat'],
  defaultDiscountRate: '5.000',
  notes: null,
  isActive: true,
  customFields: {},
};

describe('customer forms', () => {
  it('lists customers by number with their name, group and whether they are active', () => {
    expect(CUSTOMERS_LIST.columns.map((column) => column.id)).toEqual([
      'number',
      'name',
      'kind',
      'group',
      'city',
      'email',
      'status',
    ]);
    expect(CUSTOMERS_LIST.defaultSort).toEqual({ column: 'number', direction: 'asc' });
    expect(CUSTOMERS_LIST.filters?.map((filter) => filter.id)).toEqual(['kind', 'status']);
  });

  it('asks the API for the page, words, kind, status and sort the list shows', () => {
    expect(
      customerSearch({
        query: ' amel ',
        filters: { kind: 'individual', status: 'inactive' },
        sort: { column: 'group', direction: 'desc' },
        pageIndex: 2,
        pageSize: 50,
      }),
    ).toEqual({
      page: 3,
      itemsPerPage: 50,
      q: ' amel ',
      kind: 'individual',
      isActive: false,
      order: { key: 'customerGroup', direction: 'desc' },
    });
    expect(
      customerSearch({
        query: '',
        filters: { status: 'active' },
        sort: { column: 'status', direction: 'asc' },
        pageIndex: 0,
        pageSize: 25,
      }),
    ).toMatchObject({ kind: null, isActive: true, order: { key: 'isActive', direction: 'asc' } });
    expect(
      customerSearch({
        query: '',
        filters: {},
        sort: { column: 'email', direction: 'asc' },
        pageIndex: 0,
        pageSize: 25,
      }).order,
    ).toBeNull();
  });

  it('offers to sort only by what the API sorts customers by', () => {
    const list = customersList([
      {
        id: 'f1',
        entity: 'customer',
        key: 'sector',
        label: 'Secteur',
        type: 'text',
        required: false,
        choices: [],
        sortOrder: 0,
        isActive: true,
      },
    ]);

    expect(list.columns.filter((column) => column.sortable).map((column) => column.id)).toEqual([
      'number',
      'name',
      'kind',
      'group',
      'city',
      'status',
    ]);
  });

  it("builds the form from the company's preset, groups and taxes", () => {
    const form = customerForm(options, groups);
    const fields = form.sections.flatMap((section) => section.fields);
    const field = (id: string) => fields.find((candidate) => candidate.id === id);

    expect(form.sections.map((section) => section.id)).toEqual([
      'identity',
      'identifiers',
      'contact',
      'billing',
      'shipping',
      'terms',
      'notes',
    ]);
    expect(field('identifier__matricule_fiscal')).toMatchObject({
      label: 'Matricule fiscal',
      pattern: '^[0-9]{7}[A-Z]/[A-Z]/[A-Z]/[0-9]{3}$',
      visibleWhen: { field: 'kind', oneOf: ['company'] },
    });
    expect(field('identifier__matricule_fiscal')?.required).toBeFalsy();
    expect(field('customerGroupId')?.options).toEqual([
      { value: '', label: 'customers.form.no_group' },
      { value: 'g1', label: 'Grossistes' },
    ]);
    expect(field('taxRegime')?.options?.map((option) => option.value)).toEqual([
      'standard',
      'exempt',
    ]);
    expect(field('tax__t-vat')).toMatchObject({ kind: 'checkbox', label: 'TVA 19 %' });
    expect(field('number')).toMatchObject({ required: true, maxLength: 32 });
  });

  it('starts a new customer as a business in the company country under the first regime', () => {
    const values = customerValues(null, options);

    expect(values).toMatchObject({
      kind: 'company',
      taxRegime: 'standard',
      billingCountryCode: 'TN',
      customerGroupId: '',
      isActive: true,
      'tax__t-vat': false,
    });
  });

  it('round-trips a customer through the form, sending empty fields as no value', () => {
    const values = customerValues(carthage, options);
    expect(values).toMatchObject({
      number: 'CLI-0001',
      identifier__matricule_fiscal: '1234567A/B/M/000',
      customerGroupId: 'g1',
      'tax__t-vat': true,
      'tax__t-fodec': false,
      defaultDiscountRate: '5.000',
      shippingCity: '',
    });

    const input = customerInput({ ...values, legalName: '  ', shippingCity: 'Sfax' }, options);

    expect(input).toEqual({
      ...carthage,
      id: undefined,
      shippingAddress: {
        line1: null,
        line2: null,
        postalCode: null,
        city: 'Sfax',
        countryCode: null,
      },
    });
  });

  it("drops an individual's identifiers and a group left empty", () => {
    const values = {
      ...customerValues(carthage, options),
      kind: 'individual',
      customerGroupId: '',
    };

    const input = customerInput(values, options);

    expect(input.identifiers).toEqual({});
    expect(input.customerGroupId).toBeNull();
    expect(input.shippingAddress).toBeNull();
  });

  it('round-trips groups and contacts', () => {
    expect(groupInput({ ...groupValues(groups[0]!), description: ' ' })).toEqual({
      name: 'Grossistes',
      description: null,
    });
    expect(groupValues(null)).toEqual({ name: '', description: '' });

    const contact = {
      id: 'k1',
      firstName: 'Leila',
      lastName: null,
      email: 'leila@carthage.tn',
      phone: null,
      role: 'Comptable',
      isPrimary: true,
    };
    expect(contactInput(contactValues(contact))).toEqual({ ...contact, id: undefined });
  });
});
