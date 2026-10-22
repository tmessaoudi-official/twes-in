// SPDX-License-Identifier: AGPL-3.0-or-later

import { IDENTIFIER_FIELD_PREFIX } from '../shared/form/field-refusals';
import { VENDORS_LIST, vendorForm, vendorInput, vendorSearch, vendorValues } from './vendor-forms';
import type { VendorOptions, VendorRow } from './vendors-types';
import { buildFormGroup } from '../shared/form/form-builder';

const options: VendorOptions = {
  countryCode: 'TN',
  identifiers: [
    {
      key: 'matricule_fiscal',
      label: 'Matricule fiscal',
      pattern: '^[0-9]{7}[A-Z]/[A-Z]/[A-Z]/[0-9]{3}$',
    },
  ],
  expenseCategories: null,
};

/** The expenses module on: Loyer is archived, offered only to a vendor that still holds it. */
const withCategories: VendorOptions = {
  ...options,
  expenseCategories: [
    { id: 'k1', name: 'Fournitures', active: true },
    { id: 'k2', name: 'Loyer', active: false },
  ],
};

const sotumag: VendorRow = {
  id: 'v1',
  version: 1,
  number: 'FRN-0001',
  name: 'Sotumag',
  legalName: null,
  identifiers: { matricule_fiscal: '1234567A/B/M/000' },
  email: 'compta@sotumag.tn',
  phone: null,
  website: null,
  address: {
    line1: 'Zone industrielle',
    line2: null,
    postalCode: null,
    city: 'Ben Arous',
    countryCode: 'TN',
  },
  iban: 'TN5910006035183598478831',
  bic: null,
  paymentTermsDays: 0,
  notes: null,
  isActive: false,
  defaultExpenseCategoryId: 'k2',
  mergedInto: null,
  toCheck: false,
  checkNote: null,
  nameDoubtful: false,
  checkReasons: [],
};

const fieldIds = (descriptor: ReturnType<typeof vendorForm>, section: string) =>
  descriptor.sections.find((each) => each.id === section)?.fields.map((field) => field.id);

const categoryField = (descriptor: ReturnType<typeof vendorForm>) =>
  descriptor.sections
    .flatMap((section) => section.fields)
    .find((field) => field.id === 'defaultExpenseCategoryId');

describe('vendor forms', () => {
  it('asks the API for the page, words, status and sort the list shows, and sorts only by what it sorts', () => {
    expect(
      vendorSearch({
        query: 'sotu',
        filters: {
          status: 'inactive',
          'paymentTermsDays.min': '30',
          'createdAt.from': '2026-03-01',
          'createdAt.to': '2026-02-30',
        },
        sort: { column: 'status', direction: 'desc' },
        pageIndex: 1,
        pageSize: 50,
      }),
    ).toEqual({
      page: 2,
      itemsPerPage: 50,
      q: 'sotu',
      isActive: false,
      toCheck: null,
      intervals: { 'paymentTermsDays.min': '30', 'createdAt.from': '2026-03-01' },
      order: { key: 'isActive', direction: 'desc' },
    });
    const sortable = VENDORS_LIST.columns.filter((column) => column.sortable);
    for (const column of sortable) {
      expect(
        vendorSearch({
          query: '',
          filters: {},
          sort: { column: column.id, direction: 'asc' },
          pageIndex: 0,
          pageSize: 25,
        }).order,
      ).not.toBeNull();
    }
  });

  it('narrows the list to the vendors to check, or to the others, as the API does', () => {
    const asked = (toCheck?: string) =>
      vendorSearch({
        query: '',
        filters: toCheck === undefined ? {} : { toCheck },
        sort: null,
        pageIndex: 0,
        pageSize: 25,
      }).toCheck;
    expect([asked('yes'), asked('no'), asked()]).toEqual([true, false, null]);

    const filter = VENDORS_LIST.filters?.find((each) => each.id === 'toCheck');
    expect(filter?.options?.map((option) => option.label)).toEqual([
      'check.filter.yes',
      'check.filter.no',
    ]);
    expect(filter?.value({ ...sotumag, toCheck: true })).toBe('yes');
    expect(filter?.value({ ...sotumag, nameDoubtful: true, checkReasons: ['name_doubtful'] })).toBe(
      'yes',
    );
    expect(filter?.value(sotumag)).toBe('no');
  });

  it('marks a vendor « À vérifier » with its note, and asks about its name only while it is in doubt', () => {
    expect(fieldIds(vendorForm(options), 'check')).toEqual(['toCheck', 'checkNote']);
    expect(fieldIds(vendorForm(options, null, true), 'check')).toEqual([
      'toCheck',
      'nameDoubtful',
      'checkNote',
    ]);
    // Without its box, no value: one would read as an unsaved change for ever.
    expect(vendorValues(sotumag, options)).not.toHaveProperty('nameDoubtful');
    const doubtful = { ...sotumag, nameDoubtful: true, checkReasons: ['name_doubtful' as const] };
    expect(vendorValues(doubtful, options)).toMatchObject({ nameDoubtful: true });
    expect(vendorInput(vendorValues(doubtful, options), options).nameDoubtful).toBe(true);
    expect(
      vendorInput({ ...vendorValues(doubtful, options), nameDoubtful: false }, options)
        .nameDoubtful,
    ).toBe(false);
  });

  it('keeps a note only with its mark', () => {
    const marked = { ...sotumag, toCheck: true, checkNote: 'RIB à confirmer' };
    expect(vendorValues(marked, options)).toMatchObject({
      toCheck: true,
      checkNote: 'RIB à confirmer',
    });
    expect(vendorInput(vendorValues(marked, options), options)).toMatchObject({
      toCheck: true,
      checkNote: 'RIB à confirmer',
    });
    expect(
      vendorInput({ ...vendorValues(marked, options), toCheck: false }, options),
    ).toMatchObject({ toCheck: false, checkNote: null });
  });

  it('offers the intervals ruled for the list', () => {
    expect(VENDORS_LIST.ranges?.map((range) => [range.id, range.kind])).toEqual([
      ['paymentTermsDays', 'amount'],
      ['createdAt', 'day'],
    ]);
  });

  it('lists vendors by number with their city, payment terms and whether they are active', () => {
    expect(VENDORS_LIST.columns.map((column) => column.id)).toEqual([
      'number',
      'name',
      'city',
      'email',
      'paymentTermsDays',
      'status',
    ]);
    expect(VENDORS_LIST.defaultSort).toEqual({ column: 'number', direction: 'asc' });
    expect(VENDORS_LIST.columns.find((c) => c.id === 'paymentTermsDays')?.value(sotumag)).toBe(0);
  });

  // The BIC as a RIB prints it, its groups apart: the API drops the spaces before its own check.
  it('takes a BIC written with its groups apart', () => {
    const bic = buildFormGroup(vendorForm(options), vendorValues(null, options)).controls['bic']!;
    for (const taken of ['BIATTNTT', 'BIAT TN TT', 'biat tn tt', 'BNORFRPPBDX', 'BNOR FR PP BDX']) {
      bic.setValue(taken);
      expect(bic.errors, taken).toBeNull();
    }
    for (const refused of ['BIAT', 'BIATTNTT1', 'BIAT  TN TT', 'BIAT TN TT 1']) {
      bic.setValue(refused);
      expect(bic.errors?.['pattern'] ?? bic.errors?.['maxlength'], refused).toBeTruthy();
    }
  });

  // A pattern the browser checks answers first, so its refusal says the format and an example, as the API's would.
  it('says the format of every field it checks against a pattern', () => {
    const unsaid = vendorForm(options)
      .sections.flatMap((section) => section.fields)
      // A preset identifier's hint is the preset's (row 103): the API sends it beside the pattern.
      .filter((field) => !field.id.startsWith(IDENTIFIER_FIELD_PREFIX))
      .filter((field) => field.pattern !== undefined && !field.patternError && !field.hint)
      .map((field) => field.id);
    expect(unsaid).toEqual([]);
  });

  // docs/SPEC.md § 7, 2026-10-22 18:09: a bare RIB is the IBAN TN59 spells before it, so the field lets one through.
  it('takes an IBAN or a bare RIB in the IBAN field, and nothing else', () => {
    const iban = buildFormGroup(vendorForm(options), vendorValues(null, options)).controls['iban']!;
    for (const taken of [
      'TN59 1000 6035 1835 9847 8831',
      '10006035183598478831',
      '10-006-035-1835984788-31',
    ]) {
      iban.setValue(taken);
      expect(iban.errors, taken).toBeNull();
    }
    for (const refused of [
      '1000603518359847883',
      'TN59',
      'TN59 1000 6035 18!!',
      '!!10006035183598478831',
    ]) {
      iban.setValue(refused);
      expect(iban.errors?.['pattern'], refused).toBeTruthy();
    }
  });

  it("asks for the preset's registration numbers without requiring any", () => {
    const form = vendorForm(options);

    expect(form.sections.map((section) => section.id)).toEqual([
      'identity',
      'identifiers',
      'contact',
      'address',
      'payment',
      'notes',
      'check',
    ]);
    const identifier = form.sections[1]!.fields[0]!;
    expect([identifier.id, identifier.required ?? false, identifier.pattern]).toEqual([
      'identifier__matricule_fiscal',
      false,
      '^[0-9]{7}[A-Z]/[A-Z]/[A-Z]/[0-9]{3}$',
    ]);
    expect(
      vendorForm({ countryCode: 'TN', identifiers: [], expenseCategories: null }).sections.map(
        (s) => s.id,
      ),
    ).not.toContain('identifiers');
  });

  it("starts a new vendor active in the company's country, and an existing one at its values", () => {
    expect(vendorValues(null, options)).toMatchObject({
      number: '',
      isActive: true,
      countryCode: 'TN',
      paymentTermsDays: '',
      identifier__matricule_fiscal: '',
    });
    expect(vendorValues(sotumag, options)).toMatchObject({
      city: 'Ben Arous',
      isActive: false,
      paymentTermsDays: '0',
      identifier__matricule_fiscal: '1234567A/B/M/000',
    });
  });

  it('sends trimmed values, an empty field as no value and the terms as days', () => {
    const input = vendorInput(
      {
        ...vendorValues(null, options),
        number: ' FRN-0002 ',
        name: ' Sotumag ',
        countryCode: 'fr',
        iban: ' ',
        paymentTermsDays: ' 45 ',
        identifier__matricule_fiscal: '  ',
      },
      options,
    );

    expect(input).toMatchObject({
      number: 'FRN-0002',
      name: 'Sotumag',
      identifiers: {},
      iban: null,
      paymentTermsDays: 45,
      isActive: true,
      address: { line1: null, line2: null, postalCode: null, city: null, countryCode: 'FR' },
    });
    expect(vendorInput(vendorValues(null, options), options).paymentTermsDays).toBeNull();
  });

  // The API replaces the whole vendor, so a value the form does not carry must still be sent back.
  it("offers the usual expense category while the expenses module is on, the vendor's own archived one too", () => {
    expect(categoryField(vendorForm(options))).toBeUndefined();
    expect(categoryField(vendorForm(withCategories))?.options).toEqual([
      { value: '', label: 'expenses.form.no_category' },
      { value: 'k1', label: 'Fournitures' },
    ]);
    expect(
      categoryField(vendorForm(withCategories, 'k2'))?.options?.map((option) => option.value),
    ).toEqual(['', 'k1', 'k2']);
  });

  it('sends the category chosen, and keeps the one held when the form does not show it', () => {
    // Off: no control holds it, so it is never an unsaved change, and a save sends back what the vendor has.
    expect(vendorValues(sotumag, options)).not.toHaveProperty('defaultExpenseCategoryId');
    expect(
      vendorInput(vendorValues(sotumag, options), options, 'k2').defaultExpenseCategoryId,
    ).toBe('k2');

    expect(vendorValues(sotumag, withCategories)['defaultExpenseCategoryId']).toBe('k2');
    const values = vendorValues(sotumag, withCategories);
    expect(
      vendorInput({ ...values, defaultExpenseCategoryId: 'k1' }, withCategories, 'k2')
        .defaultExpenseCategoryId,
    ).toBe('k1');
    expect(
      vendorInput({ ...values, defaultExpenseCategoryId: '' }, withCategories, 'k2')
        .defaultExpenseCategoryId,
    ).toBeNull();
  });
});
