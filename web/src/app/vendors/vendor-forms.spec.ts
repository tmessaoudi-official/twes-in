// SPDX-License-Identifier: AGPL-3.0-or-later

import { VENDORS_LIST, vendorForm, vendorInput, vendorSearch, vendorValues } from './vendor-forms';
import type { VendorOptions, VendorRow } from './vendors-types';

const options: VendorOptions = {
  countryCode: 'TN',
  identifiers: [
    {
      key: 'matricule_fiscal',
      label: 'Matricule fiscal',
      pattern: '^[0-9]{7}[A-Z]/[A-Z]/[A-Z]/[0-9]{3}$',
    },
  ],
};

const sotumag: VendorRow = {
  id: 'v1',
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
};

describe('vendor forms', () => {
  it('asks the API for the page, words, status and sort the list shows, and sorts only by what it sorts', () => {
    expect(
      vendorSearch({
        query: 'sotu',
        filters: { status: 'inactive' },
        sort: { column: 'status', direction: 'desc' },
        pageIndex: 1,
        pageSize: 50,
      }),
    ).toEqual({
      page: 2,
      itemsPerPage: 50,
      q: 'sotu',
      isActive: false,
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

  it("asks for the preset's registration numbers without requiring any", () => {
    const form = vendorForm(options);

    expect(form.sections.map((section) => section.id)).toEqual([
      'identity',
      'identifiers',
      'contact',
      'address',
      'payment',
      'notes',
    ]);
    const identifier = form.sections[1]!.fields[0]!;
    expect([identifier.id, identifier.required ?? false, identifier.pattern]).toEqual([
      'identifier__matricule_fiscal',
      false,
      '^[0-9]{7}[A-Z]/[A-Z]/[A-Z]/[0-9]{3}$',
    ]);
    expect(
      vendorForm({ countryCode: 'TN', identifiers: [] }).sections.map((s) => s.id),
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
});
