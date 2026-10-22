// SPDX-License-Identifier: AGPL-3.0-or-later

import { IDENTIFIER_FIELD_PREFIX } from '../shared/form/field-refusals';
import {
  CONTACT_FORM,
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
  { id: 'g1', name: 'Grossistes', description: null, customerCount: 2, version: 1 },
];

const carthage: CustomerRow = {
  id: 'c1',
  version: 1,
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
  mergedInto: null,
  toCheck: false,
  checkNote: null,
  checkReasons: [],
  missingIdentifiers: [],
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
    expect(CUSTOMERS_LIST.filters?.map((filter) => [filter.id, filter.multiple ?? false])).toEqual([
      ['kind', true],
      ['status', false],
      ['group', true],
      ['regime', true],
      ['toCheck', false],
    ]);
    expect(CUSTOMERS_LIST.ranges?.map((range) => [range.id, range.kind])).toEqual([
      ['createdAt', 'day'],
    ]);
  });

  it('asks the API for the page, words, kind, status and sort the list shows', () => {
    expect(
      customerSearch({
        query: ' amel ',
        filters: {
          kind: 'individual,robot',
          status: 'inactive',
          group: '0199a1b2-0000-7000-8000-000000000001,not-an-id',
          regime: 'exempt,export',
          'createdAt.from': '2026-03-01',
          'createdAt.to': '2026-02-30',
        },
        sort: { column: 'group', direction: 'desc' },
        pageIndex: 2,
        pageSize: 50,
      }),
    ).toEqual({
      page: 3,
      itemsPerPage: 50,
      q: ' amel ',
      kinds: ['individual'],
      groupIds: ['0199a1b2-0000-7000-8000-000000000001'],
      regimes: ['exempt', 'export'],
      isActive: false,
      toCheck: null,
      intervals: { 'createdAt.from': '2026-03-01' },
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
    ).toMatchObject({ kinds: [], isActive: true, order: { key: 'isActive', direction: 'asc' } });
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
        version: 1,
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

  // A pattern the browser checks answers first, so its refusal says the format and an example, as the API's would.
  it('says the format of every field it checks against a pattern, on the customer and on a contact', () => {
    const unsaid = [customerForm(options, groups), CONTACT_FORM]
      .flatMap((form) => form.sections.flatMap((section) => section.fields))
      // A preset identifier's hint is the preset's (row 103): the API sends it beside the pattern.
      .filter((field) => !field.id.startsWith(IDENTIFIER_FIELD_PREFIX))
      .filter((field) => field.pattern !== undefined && !field.patternError && !field.hint)
      .map((field) => field.id);
    expect(unsaid).toEqual([]);
  });

  // docs/SPEC.md § 7, 2026-09-19 21:55: the discount rate shows and takes the locale's decimal separator.
  it('asks the default discount rate as a decimal', () => {
    const fields = customerForm(options, groups).sections.flatMap((section) => section.fields);
    expect(fields.filter((field) => field.kind === 'decimal').map((field) => field.id)).toEqual([
      'defaultDiscountRate',
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
      'check',
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
    expect(field('defaultTaxComponentIds')).toMatchObject({ kind: 'multiselect' });
    expect(field('defaultTaxComponentIds')?.options?.map((option) => option.value)).toEqual([
      't-vat',
      't-fodec',
    ]);
    expect(field('defaultTaxComponentIds')?.options?.[0]?.label).toBe('TVA 19 %');
    expect(field('number')).toMatchObject({ required: true, maxLength: 32 });
  });

  // A number the preset reads part by part is judged by the API alone, which takes every way of writing it: a
  // pattern here would refuse « 1234567aam000 » before the API could store it as 1234567A/A/M/000.
  it('leaves a number read part by part to the API, and shows the preset’s hint under it', () => {
    const parts: CustomerOptions = {
      ...options,
      identifiers: [
        {
          key: 'matricule_fiscal',
          label: 'Matricule fiscal',
          pattern: null,
          requiredForBusiness: true,
          hint: 'Exemple : 1234567A/B/M/000.',
        },
      ],
    };
    const fields = customerForm(parts, groups).sections.flatMap((section) => section.fields);
    const matricule = fields.find((field) => field.id === 'identifier__matricule_fiscal');

    expect(matricule?.pattern).toBeUndefined();
    expect(matricule?.hint).toBe('Exemple : 1234567A/B/M/000.');
  });

  it('starts a new customer as a business in the company country under the first regime', () => {
    const values = customerValues(null, options);

    expect(values).toMatchObject({
      kind: 'company',
      taxRegime: 'standard',
      billingCountryCode: 'TN',
      customerGroupId: '',
      isActive: true,
      defaultTaxComponentIds: [],
    });
  });

  it('round-trips a customer through the form, sending empty fields as no value', () => {
    const values = customerValues(carthage, options);
    expect(values).toMatchObject({
      number: 'CLI-0001',
      identifier__matricule_fiscal: '1234567A/B/M/000',
      customerGroupId: 'g1',
      defaultTaxComponentIds: ['t-vat'],
      defaultDiscountRate: '5.000',
      shippingCity: '',
    });

    const input = customerInput({ ...values, legalName: '  ', shippingCity: 'Sfax' }, options);

    expect(input).toEqual({
      ...carthage,
      id: undefined,
      version: undefined,
      // A form never writes the merge, which only « Fusionner… » does, nor what the API works out.
      mergedInto: undefined,
      checkReasons: undefined,
      missingIdentifiers: undefined,
      shippingAddress: {
        line1: null,
        line2: null,
        postalCode: null,
        city: 'Sfax',
        countryCode: null,
      },
    });
  });

  it('narrows the list to the customers to check, or to those with nothing to check', () => {
    const asked = (toCheck: string | undefined) =>
      customerSearch({
        query: '',
        filters: toCheck === undefined ? {} : { toCheck },
        sort: null,
        pageIndex: 0,
        pageSize: 25,
      }).toCheck;
    expect([asked('yes'), asked('no'), asked(undefined), asked('maybe')]).toEqual([
      true,
      false,
      null,
      null,
    ]);
    const filter = CUSTOMERS_LIST.filters?.find((candidate) => candidate.id === 'toCheck');
    expect(filter?.label).toBe('check.filter.to_check');
    expect(filter?.options.map((option) => [option.value, option.label])).toEqual([
      ['yes', 'check.filter.yes'],
      ['no', 'check.filter.no'],
    ]);
    const row = { ...carthage, groupName: null };
    expect(
      [
        row,
        { ...row, toCheck: true },
        { ...row, checkReasons: ['identifier_missing' as const] },
      ].map((candidate) => filter?.value(candidate)),
    ).toEqual(['no', 'yes', 'yes']);
  });

  it('marks a customer to check with its note, and unticking drops the note with the mark', () => {
    const section = customerForm(options, groups).sections.find((each) => each.id === 'check');
    expect(section?.title).toBe('check.form.section');
    expect(section?.fields.map((field) => [field.id, field.kind])).toEqual([
      ['toCheck', 'checkbox'],
      ['checkNote', 'textarea'],
    ]);
    expect(section?.fields[0]?.hint).toBe('check.form.to_check_hint_customer');
    expect(section?.fields[1]).toMatchObject({
      maxLength: 500,
      visibleWhen: { field: 'toCheck', oneOf: ['true'] },
    });

    const marked = { ...carthage, toCheck: true, checkNote: 'matricule à demander' };
    const values = customerValues(marked, options);
    expect([values['toCheck'], values['checkNote']]).toEqual([true, 'matricule à demander']);
    expect(customerInput(values, options)).toMatchObject({
      toCheck: true,
      checkNote: 'matricule à demander',
    });
    expect(customerInput({ ...values, checkNote: '  ' }, options).checkNote).toBeNull();
    expect(customerInput({ ...values, toCheck: false }, options)).toMatchObject({
      toCheck: false,
      checkNote: null,
    });
    expect(customerValues(null, options)).toMatchObject({ toCheck: false, checkNote: '' });
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
      version: 3,
    };
    // The version is the row's, which a revision sends beside what was typed, never part of it.
    expect(contactInput(contactValues(contact))).toEqual({
      ...contact,
      id: undefined,
      version: undefined,
    });
  });
});
