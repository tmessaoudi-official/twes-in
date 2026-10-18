// SPDX-License-Identifier: AGPL-3.0-or-later

import type { PurchaseOrderLine, Receiving } from './purchases-types';
import {
  bringsGoods,
  receiptInput,
  receiptRefusal,
  receiveRows,
  rowProblem,
  type ReceiveRow,
} from './receipt-forms';

function line(id: string, patch: Partial<PurchaseOrderLine> = {}): PurchaseOrderLine {
  return {
    id,
    productId: `p-${id}`,
    productReference: `REF-${id}`,
    productTracking: 'none',
    description: `Article ${id}`,
    quantity: '20.000',
    unitId: 'u1',
    unitCode: 'C62',
    unitName: 'pièce',
    unitPriceNet: '2.5000',
    net: '50.000',
    received: '0.000',
    outstanding: '20.000',
    ...patch,
  };
}

const receiving: Receiving = {
  places: [
    { id: 'site', parentId: null, code: 'MAG', name: 'Magasin', isDefault: true },
    { id: 'r1', parentId: 'site', code: 'R1', name: 'Rayon 1', isDefault: false },
  ],
  homes: { a: 'r1' },
  asksCostBasis: true,
};

function row(patch: Partial<ReceiveRow> & { line: PurchaseOrderLine }): ReceiveRow {
  return {
    quantity: '',
    locationId: 'site',
    lotCode: '',
    lotExpiresOn: '',
    serials: '',
    unitCost: '',
    ...patch,
  };
}

describe('receiveRows', () => {
  it('opens on everything outstanding, at each line’s home or the default place, at the order’s price', () => {
    const rows = receiveRows(
      [
        line('a'),
        line('b', { outstanding: '7.500', unitPriceNet: null }),
        line('c', { outstanding: '0.000', received: '20.000' }),
      ],
      receiving,
    );

    expect(
      rows.map((each) => [each.line.id, each.quantity, each.locationId, each.unitCost]),
    ).toEqual([
      ['a', '20', 'r1', '2.5'],
      ['b', '7.5', 'site', ''],
    ]);
  });

  it('leaves a serial-tracked line at nothing: its pieces are named one by one', () => {
    const [serial] = receiveRows([line('s', { productTracking: 'serial' })], receiving);
    expect(serial?.quantity).toBe('');
    expect(serial === undefined ? true : bringsGoods(serial)).toBe(false);
  });
});

describe('rowProblem', () => {
  it('lets a row left at nothing through, and refuses one that is not a quantity', () => {
    expect(rowProblem(row({ line: line('a'), quantity: '' }), false)).toBeNull();
    expect(rowProblem(row({ line: line('a'), quantity: '0' }), false)).toBeNull();
    expect(rowProblem(row({ line: line('a'), quantity: '1.2345' }), false)).toBe('quantity');
  });

  it('refuses more than is still expected, compared exactly, and no place', () => {
    const half = line('a', { outstanding: '0.500' });
    expect(rowProblem(row({ line: half, quantity: '0.5' }), false)).toBeNull();
    expect(rowProblem(row({ line: half, quantity: '0.501' }), false)).toBe('over');
    expect(rowProblem(row({ line: half, quantity: '0.5', locationId: '' }), false)).toBe('place');
  });

  it('asks a lot of a lot-tracked product, within the API’s length', () => {
    const lot = line('l', { productTracking: 'lot' });
    expect(rowProblem(row({ line: lot, quantity: '2' }), false)).toBe('lot');
    expect(rowProblem(row({ line: lot, quantity: '2', lotCode: 'L'.repeat(41) }), false)).toBe(
      'lot_length',
    );
    expect(rowProblem(row({ line: lot, quantity: '2', lotCode: 'L-26' }), false)).toBeNull();
  });

  it('refuses a serial number twice, and more numbers than pieces expected', () => {
    const serial = line('s', { productTracking: 'serial', outstanding: '2.000' });
    expect(rowProblem(row({ line: serial, serials: 'SN1\n\nSN2\n' }), false)).toBeNull();
    expect(rowProblem(row({ line: serial, serials: 'SN1\nSN1' }), false)).toBe('serials_repeated');
    expect(rowProblem(row({ line: serial, serials: 'SN1\nSN2\nSN3' }), false)).toBe('serials_over');
  });

  it('checks a cost only where the person may read costs', () => {
    const typed = row({ line: line('a'), quantity: '1', unitCost: '2,5' });
    expect(rowProblem(typed, true)).toBe('unit_cost');
    expect(rowProblem(typed, false)).toBeNull();
  });
});

describe('receiptInput', () => {
  it('sends a line per row that brings goods, a piece per serial number, and where each came from', () => {
    const rows = [
      row({ line: line('a'), quantity: '5', locationId: 'r1', unitCost: '2.4' }),
      row({ line: line('b'), quantity: '' }),
      row({ line: line('s', { productTracking: 'serial' }), serials: ' SN1 \nSN2', unitCost: '9' }),
      row({
        line: line('l', { productTracking: 'lot' }),
        quantity: '3',
        lotCode: ' L-26 ',
        lotExpiresOn: '2027-01-31',
      }),
    ];

    const { input, sources } = receiptInput('2026-10-18', ' BL 77 ', 'last', rows, true);

    expect(input).toEqual({
      receivedOn: '2026-10-18',
      supplierReference: 'BL 77',
      apply: 'last',
      lines: [
        {
          orderLineId: 'a',
          locationId: 'r1',
          quantity: '5',
          lotCode: null,
          lotExpiresOn: null,
          unitCost: '2.4',
        },
        {
          orderLineId: 's',
          locationId: 'site',
          quantity: '1',
          lotCode: 'SN1',
          lotExpiresOn: null,
          unitCost: '9',
        },
        {
          orderLineId: 's',
          locationId: 'site',
          quantity: '1',
          lotCode: 'SN2',
          lotExpiresOn: null,
          unitCost: '9',
        },
        {
          orderLineId: 'l',
          locationId: 'site',
          quantity: '3',
          lotCode: 'L-26',
          lotExpiresOn: '2027-01-31',
          unitCost: null,
        },
      ],
    });
    expect(sources).toEqual([0, 2, 2, 3]);
  });

  it('sends no cost, nor a cost basis, for whoever may not read costs', () => {
    const rows = [row({ line: line('a'), quantity: '5', unitCost: '2.4' })];

    const { input } = receiptInput('2026-10-18', '', 'average', rows, false);

    expect(input.supplierReference).toBeNull();
    expect(input.apply).toBeNull();
    expect(input.lines[0]?.unitCost).toBeNull();
  });

  it('never names a lot for a product that keeps none', () => {
    const rows = [
      row({ line: line('a'), quantity: '5', lotCode: 'stale', lotExpiresOn: '2027-01-01' }),
    ];
    expect(receiptInput('2026-10-18', '', null, rows, false).input.lines[0]).toMatchObject({
      lotCode: null,
      lotExpiresOn: null,
    });
  });
});

describe('receiptRefusal', () => {
  it('names the row a refused receipt line came from, and the receipt itself otherwise', () => {
    expect(receiptRefusal('lines[2].lotCode', [0, 2, 2, 3])).toEqual({ row: 2, field: 'lotCode' });
    expect(receiptRefusal('lines[0]', [1])).toEqual({ row: 1, field: 'line' });
    expect(receiptRefusal('receivedOn', [0])).toEqual({ row: null, field: 'receivedOn' });
  });
});
