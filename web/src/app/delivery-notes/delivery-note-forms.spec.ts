// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  applyProduct,
  DELIVERY_NOTES_LIST,
  deliveryNoteForm,
  deliveryNoteInput,
  deliveryNoteListRows,
  deliveryNoteValues,
  lineGroup,
  linesArray,
  offeredTaxes,
  pickedProduct,
} from './delivery-note-forms';
import type {
  CustomerOption,
  DeliveryNoteOptions,
  DeliveryNoteRow,
  ProductOption,
} from './delivery-notes-types';

const options: DeliveryNoteOptions = {
  currency: 'TND',
  currencyScale: 3,
  establishments: [
    { id: 'e0', code: 'DEPOT', name: 'Dépôt', isDefault: false },
    { id: 'e1', code: 'SIEGE', name: 'Siège', isDefault: true },
  ],
  units: [
    { id: 'u1', code: 'C62', name: 'Unité', decimals: 0 },
    { id: 'u2', code: 'KGM', name: 'Kilogramme', decimals: 3 },
  ],
  taxes: [
    { id: 't1', code: 'TVA19', name: 'TVA 19 %', family: 'vat', rate: '19', entersVatBase: false },
    { id: 't2', code: 'FODEC', name: 'FODEC 1 %', family: 'levy', rate: '1', entersVatBase: true },
  ],
};

/** What the picker answers, which is all the form ever knows about a customer or a product. */
const carthage: CustomerOption = {
  id: 'k1',
  number: 'CLI-1',
  name: 'Carthage',
  excludedFamilies: [],
};
const laptop: ProductOption = {
  id: 'p1',
  reference: 'ART-1',
  name: 'Portable 14"',
  unitId: 'u2',
  unitPriceNet: '1250.5000',
  defaultTaxComponentIds: ['t1', 't2'],
  tracking: 'none',
};

const validated: DeliveryNoteRow = {
  id: 'n1',
  number: 'BL-2026-00001',
  status: 'validated',
  customerId: 'k9',
  establishmentId: 'e1',
  recordedCustomerName: 'Ancien client',
  customerName: 'Carthage',
  issueDate: '2026-09-15',
  deliveryDate: '2026-09-20',
  deliveryAddress: {
    line1: 'Quai 3',
    line2: null,
    postalCode: '1000',
    city: 'Tunis',
    countryCode: 'TN',
  },
  customerReference: 'PO-77',
  remarksPrinted: 'Livrer au quai 3.',
  notesInternal: null,
  lines: [
    {
      productId: null,
      description: 'Pièce',
      quantity: '2.000',
      unitId: 'u1',
      unitPriceNet: '10.0000',
      taxComponentIds: ['t1'],
      productReference: null,
      productName: null,
      productTracking: null,
      lotCode: null,
      net: '20.000',
    },
  ],
  subtotalNet: '20.000',
  taxes: [{ code: 'TVA19', rate: '19', base: '20.000', amount: '3.800' }],
  totalTax: '3.800',
  total: '23.8',
};

describe('delivery note forms', () => {
  it('lists a note with the customer it was validated with, else today’s', () => {
    const draft: DeliveryNoteRow = {
      ...validated,
      id: 'n2',
      number: null,
      status: 'draft',
      customerId: 'k1',
      recordedCustomerName: null,
    };

    const rows = deliveryNoteListRows([validated, draft]);

    expect(rows.map((row) => [row.id, row.customer, row.total])).toEqual([
      ['n1', 'Ancien client', '23.8'],
      ['n2', 'Carthage', '23.8'],
    ]);
    expect(
      DELIVERY_NOTES_LIST.columns.find((column) => column.id === 'total')?.value(rows[0]!),
    ).toBe('23.8');
  });

  it('offers the establishments and asks the customer elsewhere', () => {
    const form = deliveryNoteForm(options, validated);
    const fields = form.sections.flatMap((section) => section.fields);
    const byId = new Map(fields.map((field) => [field.id, field]));

    expect(form.sections.map((section) => section.id)).toEqual([
      'parties',
      'delivery_address',
      'remarks',
    ]);
    // The customer is a picker on the page, never a field in this descriptor: a book is not a dropdown.
    expect(byId.has('customerId')).toBe(false);
    expect(() => JSON.stringify(form)).not.toThrow();
    expect(byId.get('establishmentId')?.options?.map((option) => option.label)).toEqual([
      'DEPOT · Dépôt',
      'SIEGE · Siège',
    ]);
    expect(byId.get('deliveryDate')?.kind).toBe('date');
    expect(byId.get('customerReference')?.maxLength).toBe(64);
  });

  it('starts a new note at the default establishment and an existing one at its values', () => {
    expect(deliveryNoteValues(null, options)).toEqual({
      establishmentId: 'e1',
      customerReference: '',
      deliveryDate: '',
      deliveryAddressLine1: '',
      deliveryAddressLine2: '',
      deliveryPostalCode: '',
      deliveryCity: '',
      deliveryCountryCode: '',
      remarksPrinted: '',
      notesInternal: '',
    });
    expect(deliveryNoteValues(validated, options)).toEqual(
      expect.objectContaining({
        deliveryDate: '2026-09-20',
        deliveryAddressLine1: 'Quai 3',
        remarksPrinted: 'Livrer au quai 3.',
        notesInternal: '',
      }),
    );
  });

  it('refuses a line without a description, a zero quantity, one finer than its unit, or a malformed price', () => {
    const line = lineGroup(null, options);
    expect(line.getRawValue()).toEqual({
      productId: '',
      productReference: '',
      productName: '',
      description: '',
      quantity: '1',
      unitId: 'u1',
      unitPriceNet: '',
      taxComponentIds: [],
      lotCode: '',
      productTracking: '',
    });
    expect(line.controls.description.hasError('required')).toBe(true);

    line.patchValue({ description: 'Pièce', unitPriceNet: '10.5' });
    expect(line.valid).toBe(true);

    line.patchValue({ quantity: '0' });
    expect(line.controls.quantity.hasError('positive')).toBe(true);

    line.patchValue({ quantity: '1.5' });
    expect(line.hasError('quantityDecimals')).toBe(true);
    line.patchValue({ unitId: 'u2' });
    expect(line.valid).toBe(true);

    line.patchValue({ unitPriceNet: '10,5' });
    expect(line.controls.unitPriceNet.hasError('pattern')).toBe(true);
  });

  it('reads an existing note’s lines as their unit counts them, and a new note starts with one line', () => {
    const lines = linesArray(validated.lines, options);
    expect(lines.length).toBe(1);
    expect(lines.at(0).getRawValue()).toEqual({
      productId: '',
      productReference: '',
      productName: '',
      description: 'Pièce',
      quantity: '2',
      unitId: 'u1',
      unitPriceNet: '10.000',
      taxComponentIds: ['t1'],
      lotCode: '',
      productTracking: '',
    });
    expect(lines.valid).toBe(true);
    expect(linesArray([], options).length).toBe(1);
  });

  it('fills a line from its product, without the taxes its customer’s regime refuses', () => {
    expect(offeredTaxes(options, ['vat']).map((tax) => tax.code)).toEqual(['FODEC']);

    const line = lineGroup(null, options);
    applyProduct(line, laptop, options, []);
    expect(line.getRawValue()).toEqual({
      productId: 'p1',
      productReference: 'ART-1',
      productName: 'Portable 14"',
      description: 'Portable 14"',
      quantity: '1',
      unitId: 'u2',
      unitPriceNet: '1250.500',
      taxComponentIds: ['t1', 't2'],
      lotCode: '',
      productTracking: 'none',
    });
    // The line carries the product's own words, which is what lets the picker show it without the catalogue.
    expect(pickedProduct(line)).toEqual({ id: 'p1', code: 'ART-1', name: 'Portable 14"' });

    applyProduct(line, laptop, options, ['vat']);
    expect(line.controls.taxComponentIds.value).toEqual(['t2']);

    line.patchValue({ description: 'Tapé à la main' });
    applyProduct(line, null, options, []);
    expect(line.controls.productId.value).toBe('');
    expect(line.controls.productReference.value).toBe('');
    expect(pickedProduct(line)).toBeNull();
    expect(line.controls.description.value).toBe('Tapé à la main');
  });

  it('sends the form as the API takes it: trimmed, an empty field as no value', () => {
    const lines = linesArray([], options);
    lines.at(0).patchValue({ description: '  Pièce ', quantity: ' 2 ', unitPriceNet: '10' });

    expect(
      deliveryNoteInput(
        {
          ...deliveryNoteValues(null, options),
          customerReference: ' PO-77 ',
          deliveryCountryCode: 'tn',
        },
        lines,
        carthage.id,
      ),
    ).toEqual({
      customerId: 'k1',
      establishmentId: 'e1',
      deliveryDate: null,
      deliveryAddress: {
        line1: null,
        line2: null,
        postalCode: null,
        city: null,
        countryCode: 'TN',
      },
      customerReference: 'PO-77',
      remarksPrinted: null,
      notesInternal: null,
      lines: [
        {
          productId: null,
          description: 'Pièce',
          quantity: '2',
          unitId: 'u1',
          unitPriceNet: '10',
          taxComponentIds: [],
          lotCode: null,
        },
      ],
    });
  });

  it('names the lot or serial handed over only on a line of a product tracked by one', () => {
    // docs/SPEC.md § 7, 2026-09-24 12:40 row 5.
    const lines = linesArray([], options);
    const line = lines.at(0);
    applyProduct(line, { ...laptop, tracking: 'lot' }, options, []);
    line.patchValue({ lotCode: ' L-2409 ' });
    expect(line.valid).toBe(true);
    expect(
      deliveryNoteInput(deliveryNoteValues(null, options), lines, carthage.id).lines[0].lotCode,
    ).toBe('L-2409');

    line.patchValue({ lotCode: 'L 24' });
    expect(line.controls.lotCode.hasError('pattern')).toBe(true);

    // Another product, not tracked: the lot typed for the first one is not sent with it.
    line.patchValue({ lotCode: 'L-2409' });
    applyProduct(line, laptop, options, []);
    expect(line.controls.lotCode.value).toBe('');
    expect(
      deliveryNoteInput(deliveryNoteValues(null, options), lines, carthage.id).lines[0].lotCode,
    ).toBeNull();
  });
});
