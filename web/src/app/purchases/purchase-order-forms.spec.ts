// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  lineGroup,
  linesArray,
  plainDecimal,
  purchaseOrderInput,
  purchaseOrderSearch,
  purchaseOrderValues,
  refusedPlace,
} from './purchase-order-forms';
import type { PurchaseOrderLine, PurchaseOrderRow } from './purchases-types';

const VENDOR = '0199e5b4-1c2d-7a00-8000-000000000001';

const line: PurchaseOrderLine = {
  id: 'l1',
  productId: 'p1',
  productReference: 'VIS-6',
  productTracking: 'none',
  description: 'Vis 6 mm',
  quantity: '12.500',
  unitId: 'u1',
  unitCode: 'KGM',
  unitName: 'kilogramme',
  unitPriceNet: '2.5000',
  net: '31.250',
  received: '0.000',
  outstanding: '12.500',
};

const order: PurchaseOrderRow = {
  id: 'o1',
  version: 2,
  number: null,
  status: 'draft',
  vendorId: 'v1',
  vendorName: 'Sotumag',
  recordedVendorNumber: null,
  establishmentId: null,
  issueDate: null,
  expectedOn: '2026-10-25',
  remarksPrinted: 'Livrer le matin',
  notesInternal: null,
  lines: [line],
  totalNet: '31.250',
  receipts: [],
};

describe('purchase order forms', () => {
  it('strips the zeros the API pads a decimal with, and nothing else', () => {
    expect(plainDecimal('2.000')).toBe('2');
    expect(plainDecimal('2.5000')).toBe('2.5');
    expect(plainDecimal('100')).toBe('100');
    expect(plainDecimal('0.050')).toBe('0.05');
  });

  it('asks the API for the statuses, vendors, days and order the list shows', () => {
    expect(
      purchaseOrderSearch({
        pageIndex: 1,
        pageSize: 50,
        query: 'vis',
        filters: { status: 'sent,unknown', vendor: `${VENDOR},not-an-id` },
        sort: { column: 'vendor', direction: 'asc' },
      }),
    ).toMatchObject({
      page: 2,
      itemsPerPage: 50,
      q: 'vis',
      status: ['sent'],
      vendorIds: [VENDOR],
      order: { key: 'vendor', direction: 'asc' },
    });
  });

  it('starts a new order for the vendor it was opened for, and an existing one as it was saved', () => {
    expect(purchaseOrderValues(null, 'v9')).toEqual({
      vendorId: 'v9',
      expectedOn: '',
      remarksPrinted: '',
      notesInternal: '',
    });
    expect(purchaseOrderValues(order, 'v9')).toMatchObject({
      vendorId: 'v1',
      expectedOn: '2026-10-25',
      remarksPrinted: 'Livrer le matin',
    });
  });

  it('opens a new order on one empty line and a saved one on its lines, as typed', () => {
    expect(linesArray(null).getRawValue()).toEqual([
      { productId: '', description: '', quantity: '', unitPriceNet: '' },
    ]);
    expect(linesArray([line]).getRawValue()).toEqual([
      { productId: 'p1', description: 'Vis 6 mm', quantity: '12.5', unitPriceNet: '2.5' },
    ]);
  });

  it('refuses a line with no product, or a quantity finer than the API keeps', () => {
    const group = lineGroup(null);
    expect(group.valid).toBe(false);
    group.setValue({ productId: 'p1', description: '', quantity: '1.2345', unitPriceNet: '' });
    expect(group.controls.quantity.hasError('pattern')).toBe(true);
    group.controls.quantity.setValue('1.234');
    expect(group.valid).toBe(true);
    group.controls.unitPriceNet.setValue('0.12345');
    expect(group.controls.unitPriceNet.hasError('pattern')).toBe(true);
  });

  it('sends a price only for whoever may read costs, so the API keeps the one set before', () => {
    const lines = linesArray([line]);
    const values = purchaseOrderValues(order);

    expect(purchaseOrderInput(values, lines, true).lines).toEqual([
      { productId: 'p1', description: 'Vis 6 mm', quantity: '12.5', unitPriceNet: '2.5' },
    ]);
    expect(purchaseOrderInput(values, lines, false)).toEqual({
      vendorId: 'v1',
      establishmentId: null,
      expectedOn: '2026-10-25',
      remarksPrinted: 'Livrer le matin',
      notesInternal: null,
      lines: [{ productId: 'p1', description: 'Vis 6 mm', quantity: '12.5', unitPriceNet: null }],
    });
  });

  it('places a refusal on its line and field', () => {
    expect(refusedPlace('lines[2].quantity')).toEqual({ line: 2, field: 'quantity' });
    expect(refusedPlace('lines[0]')).toEqual({ line: 0, field: 'line' });
    expect(refusedPlace('vendorId')).toEqual({ line: null, field: 'vendorId' });
  });
});
