// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  applyProduct,
  atScale,
  deliveryNoteForm,
  deliveryNoteInput,
  deliveryNoteListRows,
  deliveryNoteValues,
  lineGroup,
  linesArray,
  offeredTaxes,
} from './delivery-note-forms';
import type { DeliveryNoteOptions, DeliveryNoteRow } from './delivery-notes-types';

const options: DeliveryNoteOptions = {
  currency: 'TND',
  currencyScale: 3,
  establishments: [
    { id: 'e0', code: 'DEPOT', name: 'Dépôt', isDefault: false },
    { id: 'e1', code: 'SIEGE', name: 'Siège', isDefault: true },
  ],
  customers: [
    { id: 'k1', number: 'CLI-1', name: 'Carthage', excludedFamilies: [] },
    { id: 'k2', number: 'CLI-2', name: 'Export SA', excludedFamilies: ['vat'] },
  ],
  products: [
    {
      id: 'p1',
      reference: 'ART-1',
      name: 'Portable 14"',
      unitId: 'u2',
      unitPriceNet: '1250.5000',
      defaultTaxComponentIds: ['t1', 't2'],
    },
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

const validated: DeliveryNoteRow = {
  id: 'n1',
  number: 'BL-2026-00001',
  status: 'validated',
  customerId: 'k9',
  establishmentId: 'e1',
  customerName: 'Ancien client',
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
      net: '20.000',
    },
  ],
  subtotalNet: '20.000',
  taxes: [{ code: 'TVA19', rate: '19', base: '20.000', amount: '3.800' }],
  totalTax: '3.800',
  total: '23.8',
};

describe('delivery note forms', () => {
  it('shows an amount at the currency scale, and finer only when it is', () => {
    expect(atScale('2525', 3)).toBe('2525.000');
    expect(atScale('2.5000', 3)).toBe('2.500');
    expect(atScale('12.3456', 3)).toBe('12.3456');
    expect(atScale('7', 0)).toBe('7');
  });

  it('lists a note with the customer it was validated with, else today’s, and its total at scale', () => {
    const draft: DeliveryNoteRow = {
      ...validated,
      id: 'n2',
      number: null,
      status: 'draft',
      customerId: 'k1',
      customerName: null,
    };

    const rows = deliveryNoteListRows([validated, draft], options);

    expect(rows.map((row) => [row.id, row.customer, row.totalShown])).toEqual([
      ['n1', 'Ancien client', '23.800'],
      ['n2', 'Carthage', '23.800'],
    ]);
  });

  it('offers the company’s customers and establishments, keeping a customer it no longer offers', () => {
    const form = deliveryNoteForm(options, validated);
    const fields = form.sections.flatMap((section) => section.fields);
    const byId = new Map(fields.map((field) => [field.id, field]));

    expect(form.sections.map((section) => section.id)).toEqual([
      'parties',
      'delivery_address',
      'remarks',
    ]);
    expect(byId.get('customerId')?.options?.map((option) => option.value)).toEqual([
      'k1',
      'k2',
      'k9',
    ]);
    expect(byId.get('customerId')?.options?.at(-1)?.label).toBe('Ancien client');
    expect(byId.get('establishmentId')?.options?.map((option) => option.label)).toEqual([
      'DEPOT · Dépôt',
      'SIEGE · Siège',
    ]);
    expect(byId.get('deliveryDate')?.kind).toBe('date');
    expect(byId.get('customerReference')?.maxLength).toBe(64);
  });

  it('starts a new note at the default establishment and an existing one at its values', () => {
    expect(deliveryNoteValues(null, options)).toEqual({
      customerId: '',
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
        customerId: 'k9',
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
      description: '',
      quantity: '1',
      unitId: 'u1',
      unitPriceNet: '',
      taxComponentIds: [],
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
      description: 'Pièce',
      quantity: '2',
      unitId: 'u1',
      unitPriceNet: '10.000',
      taxComponentIds: ['t1'],
    });
    expect(lines.valid).toBe(true);
    expect(linesArray([], options).length).toBe(1);
  });

  it('fills a line from its product, without the taxes its customer’s regime refuses', () => {
    expect(offeredTaxes(options, ['vat']).map((tax) => tax.code)).toEqual(['FODEC']);

    const line = lineGroup(null, options);
    applyProduct(line, 'p1', options, []);
    expect(line.getRawValue()).toEqual({
      productId: 'p1',
      description: 'Portable 14"',
      quantity: '1',
      unitId: 'u2',
      unitPriceNet: '1250.500',
      taxComponentIds: ['t1', 't2'],
    });

    applyProduct(line, 'p1', options, ['vat']);
    expect(line.controls.taxComponentIds.value).toEqual(['t2']);

    line.patchValue({ description: 'Tapé à la main' });
    applyProduct(line, '', options, []);
    expect(line.controls.productId.value).toBe('');
    expect(line.controls.description.value).toBe('Tapé à la main');
  });

  it('sends the form as the API takes it: trimmed, an empty field as no value', () => {
    const lines = linesArray([], options);
    lines.at(0).patchValue({ description: '  Pièce ', quantity: ' 2 ', unitPriceNet: '10' });

    expect(
      deliveryNoteInput(
        {
          ...deliveryNoteValues(null, options),
          customerId: 'k1',
          customerReference: ' PO-77 ',
          deliveryCountryCode: 'tn',
        },
        lines,
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
        },
      ],
    });
  });
});
