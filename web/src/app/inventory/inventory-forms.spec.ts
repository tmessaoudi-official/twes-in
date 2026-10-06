// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  LOCATIONS_LIST,
  locationForm,
  locationInput,
  locationLabels,
  locationListRows,
  locationValues,
  MOVEMENTS_LIST,
  movementForm,
  receiptCostForm,
  receiptCostInput,
  receiptCostValues,
  movementInput,
  receiptInput,
  movementListRows,
  movementValues,
  STOCK_LIST,
  stockListRows,
} from './inventory-forms';
import type {
  CostOnReceive,
  StockLevelRow,
  StockLocationKind,
  StockLocationRow,
  StockMovementKind,
  StockMovementRow,
  StockOptions,
  StockSourceType,
} from './inventory-types';

const options: StockOptions = {
  establishments: [
    { id: 'e1', code: '000', name: 'Siège' },
    { id: 'e2', code: '001', name: 'Dépôt' },
  ],
  planShapes: [],
  structureShapes: [],
};

function location(
  id: string,
  establishmentId: string,
  parentId: string | null,
  kind: StockLocationKind,
  code: string,
  name: string,
  isDefault = false,
): StockLocationRow {
  return {
    id,
    establishmentId,
    parentId,
    kind,
    code,
    name,
    isDefault,
    childCount: 0,
    movementCount: 0,
  };
}

function level(productId: string, locationId: string, quantity: string): StockLevelRow {
  return {
    id: `${productId}:${locationId}`,
    productId,
    productReference: `ART-${productId.slice(1)}`,
    productName: productId === 'p1' ? 'Portable' : 'Article',
    unitCode: 'p1' === productId ? 'C62' : 'KGM',
    unitDecimals: 'p1' === productId ? 0 : 3,
    locationId,
    locationCode: '000',
    locationName: 'Siège',
    establishmentId: 'e1',
    quantity,
    lotId: null,
    lotCode: null,
    lotExpiresOn: null,
    lotReleased: false,
  };
}

function movement(
  id: string,
  productId: string,
  locationId: string,
  kind: StockMovementKind,
  quantity: string,
  sourceType: StockSourceType,
  sourceId: string | null,
): StockMovementRow {
  return {
    id,
    productId,
    productReference: `ART-${productId.slice(1)}`,
    productName: productId === 'p2' ? 'Farine' : 'Article',
    unitCode: 'p1' === productId ? 'C62' : 'KGM',
    unitDecimals: 'p1' === productId ? 0 : 3,
    locationId,
    locationCode: 'l1' === locationId ? '000' : 'Z9',
    locationName: 'l1' === locationId ? 'Siège' : 'Zone froide',
    kind,
    quantity,
    sourceType,
    sourceId,
    lotCode: null,
    reason: null,
    note: null,
    recordedBy: null,
    vendorId: null,
    vendorName: null,
    supplierReference: null,
    receivedOn: null,
    at: '2026-09-15T09:00:00+00:00',
    costTyped: false,
    costToComplete: false,
  };
}

const site = location('l1', 'e1', null, 'site', '000', 'Siège', true);
const zone = location('l2', 'e1', 'l1', 'zone', 'Z1', 'Zone froide');
const rack = location('l3', 'e1', 'l2', 'rack', 'R1', 'Rayonnage 1');
const depot = location('l4', 'e2', null, 'site', '001', 'Dépôt', true);

const fieldsOf = (descriptor: ReturnType<typeof locationForm>) =>
  descriptor.sections.flatMap((section) => section.fields);

describe('locationLabels', () => {
  it('names each location by its path of codes, then its name, in the order of the paths', () => {
    expect([...locationLabels([rack, depot, zone, site]).entries()]).toEqual([
      ['l1', '000 — Siège'],
      ['l2', '000 › Z1 — Zone froide'],
      ['l3', '000 › Z1 › R1 — Rayonnage 1'],
      ['l4', '001 — Dépôt'],
    ]);
  });

  it('stops at a parent it does not know, and at a cycle', () => {
    const orphan = location('l9', 'e1', 'gone', 'bin', 'B9', 'Casier');
    const a = location('a', 'e1', 'b', 'zone', 'A', 'Allée A');
    const b = location('b', 'e1', 'a', 'zone', 'B', 'Allée B');

    const labels = locationLabels([orphan, a, b]);

    expect(labels.get('l9')).toBe('B9 — Casier');
    expect(labels.get('a')).toMatch(/A — Allée A$/);
  });
});

describe('the list rows', () => {
  it('counts stock in its product unit and marks what fell below zero', () => {
    const rows = stockListRows(
      [level('p1', 'l1', '-2.000'), level('p2', 'l1', '1.250'), level('p9', 'l1', '4.000')],
      [site],
      '2026-10-02',
    );

    expect(
      rows.map((row) => [row.productReference, row.unitDecimals, row.negative, row.locationLabel]),
    ).toEqual([
      ['ART-1', 0, true, '000 — Siège'],
      ['ART-2', 3, false, '000 — Siège'],
      ['ART-9', 3, false, '000 — Siège'],
    ]);
  });

  it('marks a lot past its day unless a person released it', () => {
    const lot = (expiresOn: string | null, released: boolean) => ({
      ...level('p1', 'l1', '4.000'),
      lotId: 'k1',
      lotCode: 'L-07',
      lotExpiresOn: expiresOn,
      lotReleased: released,
    });

    const expired = (row: ReturnType<typeof lot>) =>
      stockListRows([row], [site], '2026-10-02')[0]?.expired;

    expect(expired(lot('2026-10-01', false))).toBe(true);
    expect(expired(lot('2026-10-02', false))).toBe(false); // the last day is still in date
    expect(expired(lot('2026-10-01', true))).toBe(false);
    expect(expired(lot(null, false))).toBe(false);
  });

  it('lists locations by their path', () => {
    expect(locationListRows([rack, zone, site]).map((row) => [row.id, row.path])).toEqual([
      ['l1', '000 — Siège'],
      ['l2', '000 › Z1 — Zone froide'],
      ['l3', '000 › Z1 › R1 — Rayonnage 1'],
    ]);
  });

  it('names the product and the location of each movement', () => {
    const rows = movementListRows(
      [
        movement('m1', 'p1', 'l1', 'out', '-3.000', 'delivery_note', 'n1'),
        movement('m2', 'p2', 'l9', 'in', '2.000', 'receipt', null),
        movement('m3', 'p7', 'l1', 'adjustment', '0.000', 'count', null),
      ],
      [site],
    );

    // A movement names what it moved, so a location the company no longer keeps is still named by the row itself.
    expect(rows.map((row) => [row.productLabel, row.locationLabel, row.unitDecimals])).toEqual([
      ['ART-1 — Article', '000 — Siège', 0],
      ['ART-2 — Farine', 'Z9 — Zone froide', 3],
      ['ART-7 — Article', '000 — Siège', 3],
    ]);
  });

  it('shows the columns people read in each list', () => {
    expect(STOCK_LIST.columns.map((column) => column.id)).toEqual([
      'reference',
      'product',
      'location',
      'quantity',
      'unit',
      'lot',
      'useBy',
    ]);
    expect(MOVEMENTS_LIST.columns.map((column) => column.id)).toEqual([
      'at',
      'product',
      'location',
      'lot',
      'kind',
      'quantity',
      'source',
      'vendor',
      'supplierReference',
      'receivedOn',
    ]);
    expect(LOCATIONS_LIST.columns.map((column) => column.id)).toEqual([
      'path',
      'kind',
      'children',
      'movements',
    ]);
  });
});

describe('locationForm', () => {
  it('asks a new location for its establishment, where it sits, its kind, code and name', () => {
    const fields = fieldsOf(locationForm(options, [site, zone, rack, depot], null));

    expect(fields.map((field) => [field.id, field.kind, field.required ?? false])).toEqual([
      ['establishmentId', 'select', true],
      ['parentId', 'select', false],
      ['kind', 'select', true],
      ['code', 'text', true],
      ['name', 'text', true],
    ]);
    expect(fields[0]?.options?.map((option) => option.label)).toEqual([
      '000 — Siège',
      '001 — Dépôt',
    ]);
    expect(fields[1]?.options?.map((option) => option.value)).toEqual(['', 'l1', 'l2', 'l3', 'l4']);
    expect(fields[2]?.options?.map((option) => option.value)).toEqual([
      'site',
      'building',
      'floor',
      'zone',
      'rack',
      'bin',
      'quarantine',
    ]);
    expect(fields[3]?.pattern).toBe('[A-Za-z0-9._\\-]{1,32}');
  });

  it('keeps a location in its establishment and never offers it, or anything under it, as its parent', () => {
    const fields = fieldsOf(locationForm(options, [site, zone, rack, depot], zone));

    expect(fields[0]?.readOnly).toBe(true);
    expect(fields[1]?.options?.map((option) => option.value)).toEqual(['', 'l1']);
  });

  it('offers a default location no parent', () => {
    const fields = fieldsOf(locationForm(options, [site, zone], site));

    expect(fields[1]?.options?.map((option) => option.value)).toEqual(['']);
    expect(fields[1]?.readOnly).toBe(true);
  });

  it('offers a default location every kind but quarantine, which every other location may take', () => {
    const kinds = (editing: typeof site | null) =>
      fieldsOf(locationForm(options, [site, zone], editing))[2]?.options?.map(
        (option) => option.value,
      );

    expect(kinds(site)).not.toContain('quarantine');
    expect(kinds(site)).toContain('site');
    expect(kinds(zone)).toContain('quarantine');
    expect(kinds(null)).toContain('quarantine');
  });
});

describe('locationValues and locationInput', () => {
  it('starts a new location in the only establishment, under its default, as a zone', () => {
    const one: StockOptions = { ...options, establishments: options.establishments.slice(0, 1) };

    expect(locationValues(null, one)).toEqual({
      establishmentId: 'e1',
      parentId: '',
      kind: 'zone',
      code: '',
      name: '',
    });
    expect(locationValues(null, options)['establishmentId']).toBe('');
  });

  it('reads a location back, and sends an empty parent as none', () => {
    expect(locationValues(rack, options)).toEqual({
      establishmentId: 'e1',
      parentId: 'l2',
      kind: 'rack',
      code: 'R1',
      name: 'Rayonnage 1',
    });
    expect(
      locationInput({
        establishmentId: 'e1',
        parentId: '',
        kind: 'bin',
        code: ' B1 ',
        name: ' Casier ',
      }),
    ).toEqual({ establishmentId: 'e1', parentId: null, kind: 'bin', code: 'B1', name: 'Casier' });
  });
});

describe('the movement form', () => {
  it('asks which product, where and how much, the product as a picker', () => {
    const form = movementForm('count', [zone, site]);
    const fields = fieldsOf(form);

    expect(fields.map((field) => [field.id, field.kind, field.required ?? false])).toEqual([
      ['productId', 'pick', true],
      ['locationId', 'select', true],
      ['quantity', 'decimal', true],
    ]);
    // A catalogue is not a dropdown: the descriptor carries no product options, and stays data.
    expect(fields[0]?.options).toBeUndefined();
    expect(() => JSON.stringify(form)).not.toThrow();
    expect(fields[1]?.options?.map((option) => option.value)).toEqual(['l1', 'l2']);
    expect(fields[2]?.hint).toBe('inventory.movement.quantity_hint.count');
  });

  it('starts on no product at the first default location, and sends a decimal comma as a point', () => {
    expect(movementValues([zone, depot, site])).toEqual({
      productId: '',
      locationId: 'l1',
      toLocationId: '',
      quantity: '',
      lotCode: '',
      lotExpiresOn: '',
      unitCost: '',
      applyCost: '',
      vendorId: '',
      supplierReference: '',
      receivedOn: '',
      reason: '',
      note: '',
    });
    expect(movementValues([])).toEqual({
      productId: '',
      locationId: '',
      toLocationId: '',
      quantity: '',
      lotCode: '',
      lotExpiresOn: '',
      unitCost: '',
      applyCost: '',
      vendorId: '',
      supplierReference: '',
      receivedOn: '',
      reason: '',
      note: '',
    });
    expect(
      movementInput('receive', { productId: 'p2', locationId: 'l2', quantity: ' 1.5 ' }),
    ).toEqual({
      operation: 'receive',
      productId: 'p2',
      locationId: 'l2',
      quantity: '1.5',
    });
  });

  // docs/SPEC.md § 7, 2026-09-22 11:10 and 2026-09-23 slice 7: a tracked product's stock is a number per lot.
  it('asks a tracked product its lot, and its use-by day where goods arrive or are counted', () => {
    const shape = (form: ReturnType<typeof movementForm>) =>
      fieldsOf(form).map((field) => [field.id, field.kind, field.required ?? false]);

    expect(shape(movementForm('receive', [site], 'lot'))).toEqual([
      ['productId', 'pick', true],
      ['locationId', 'select', true],
      ['lotCode', 'text', true],
      ['lotExpiresOn', 'date', false],
      ['quantity', 'decimal', true],
      ['supplierReference', 'text', false],
      ['receivedOn', 'date', false],
    ]);
    expect(shape(movementForm('count', [site], 'lot')).map(([id]) => id)).toContain('lotExpiresOn');
    // A move takes goods of a lot that exists; its date is the lot's own, never said again.
    expect(shape(movementForm('move', [site], 'lot')).map(([id]) => id)).toEqual([
      'productId',
      'locationId',
      'toLocationId',
      'lotCode',
      'quantity',
    ]);
    expect(shape(movementForm('receive', [site], 'none')).map(([id]) => id)).not.toContain(
      'lotCode',
    );

    const lot = fieldsOf(movementForm('receive', [site], 'lot')).find((f) => f.id === 'lotCode');
    const serial = fieldsOf(movementForm('receive', [site], 'serial')).find(
      (f) => f.id === 'lotCode',
    );
    expect([lot?.label, serial?.label]).toEqual([
      'inventory.stock.fields.lot',
      'inventory.stock.fields.serial',
    ]);
    // What a scanner can read back: printable ASCII, no space, at most 40.
    const code = new RegExp(`^(?:${lot?.pattern})$`);
    expect(['L-07', 'A'.repeat(40)].every((value) => code.test(value))).toBe(true);
    expect(['L 07', 'Lé', 'A'.repeat(41)].some((value) => code.test(value))).toBe(false);
  });

  // docs/SPEC.md § 7, 2026-10-02 row 74 (b): a loss names why the goods left, so a report can tell a breakage from a theft.
  it('asks a loss its reason and an optional note, and sends both with the lot and no use-by day', () => {
    const shape = (form: ReturnType<typeof movementForm>) =>
      fieldsOf(form).map((field) => [field.id, field.kind, field.required ?? false]);

    expect(shape(movementForm('loss', [site], 'lot'))).toEqual([
      ['productId', 'pick', true],
      ['locationId', 'select', true],
      ['lotCode', 'text', true],
      ['quantity', 'decimal', true],
      ['reason', 'select', true],
      ['note', 'text', false],
    ]);
    const reasons = fieldsOf(movementForm('loss', [site])).find((f) => f.id === 'reason');
    expect(reasons?.options?.map((option) => option.value)).toEqual([
      'lost',
      'broken',
      'expired',
      'stolen',
      'internal_use',
      'sample',
    ]);
    expect(reasons?.options?.[4]?.label).toBe('inventory.loss.reasons.internal_use');

    expect(
      movementInput('loss', {
        productId: 'p2',
        locationId: 'l2',
        quantity: '2',
        reason: 'broken',
        note: ' dropped ',
        lotCode: 'L1',
        lotExpiresOn: '2026-12-01',
      }),
    ).toEqual({
      operation: 'loss',
      productId: 'p2',
      locationId: 'l2',
      quantity: '2',
      reason: 'broken',
      note: 'dropped',
      lotCode: 'L1',
    });
    // Nothing of a reason is sent for any other movement, whatever was left in the values.
    expect(
      movementInput('receive', {
        productId: 'p2',
        locationId: 'l2',
        quantity: '2',
        reason: 'broken',
        note: 'x',
      }),
    ).toEqual({ operation: 'receive', productId: 'p2', locationId: 'l2', quantity: '2' });
  });

  it('sends the lot a person named, and nothing of a lot nobody named', () => {
    const base = { productId: 'p2', locationId: 'l2', quantity: '2' };
    expect(
      movementInput('receive', { ...base, lotCode: ' L-07 ', lotExpiresOn: '2027-05-31' }),
    ).toEqual({ operation: 'receive', ...base, lotCode: 'L-07', lotExpiresOn: '2027-05-31' });
    expect(movementInput('receive', { ...base, lotCode: '', lotExpiresOn: '' })).toEqual({
      operation: 'receive',
      ...base,
    });
    expect(
      movementInput('move', {
        ...base,
        toLocationId: 'l1',
        lotCode: 'L-07',
        lotExpiresOn: '2027-05-31',
      }),
    ).toEqual({ operation: 'move', ...base, toLocationId: 'l1', lotCode: 'L-07' });
  });

  it('asks a move where the goods go, and offers every location for it', () => {
    const fields = fieldsOf(movementForm('move', [zone, site]));

    expect(fields.map((field) => field.id)).toEqual([
      'productId',
      'locationId',
      'toLocationId',
      'quantity',
    ]);
    expect(fields[2]?.options?.map((option) => option.value)).toEqual(['l1', 'l2']);
    expect(fields[2]?.required).toBe(true);
  });

  it('sends where a move goes, and nothing of the sort for the other two', () => {
    // The field is on the body only for a move: a receipt and a count have no destination, and a body naming an
    // empty one would be saying something was chosen when nothing was. The API ignores it rather than refusing it,
    // so nothing but this keeps the request honest.
    expect(
      movementInput('move', {
        productId: 'p2',
        locationId: 'l1',
        toLocationId: ' l2 ',
        quantity: '3',
      }),
    ).toEqual({
      operation: 'move',
      productId: 'p2',
      locationId: 'l1',
      toLocationId: 'l2',
      quantity: '3',
    });
    expect(
      movementInput('count', {
        productId: 'p2',
        locationId: 'l1',
        toLocationId: 'l2',
        quantity: '3',
      }),
    ).not.toHaveProperty('toLocationId');
    expect(movementValues([zone, depot, site])['toLocationId']).toBe('');
  });
});

// docs/SPEC.md § 7: a receipt may say what a unit cost, asked only of someone who may read what things cost.
describe('the cost of a receipt', () => {
  const fieldsOf = (operation: 'receive' | 'count' | 'move', withCost: boolean) =>
    movementForm(operation, [], 'none', withCost)
      .sections.flatMap((section) => section.fields)
      .map((field) => field.id);

  it('is asked on a receipt for a person who may read costs, and nowhere else', () => {
    expect(fieldsOf('receive', true)).toContain('unitCost');
    expect(fieldsOf('receive', false)).not.toContain('unitCost');
    expect(fieldsOf('count', true)).not.toContain('unitCost');
    expect(fieldsOf('move', true)).not.toContain('unitCost');
  });

  it('is sent trimmed on a receipt when given, and left out when empty or on another operation', () => {
    const values = { ...movementValues([]), productId: 'p1', locationId: 'l1', quantity: '2' };

    expect(movementInput('receive', { ...values, unitCost: ' 12.5 ' }).unitCost).toBe('12.5');
    expect(movementInput('receive', { ...values, unitCost: '' })).not.toHaveProperty('unitCost');
    expect(movementInput('count', { ...values, unitCost: '12.5' })).not.toHaveProperty('unitCost');
  });

  it('offers the choice of what the receipt does to the cost only where the company leaves it to the person', () => {
    const asked = (operation: 'receive' | 'count', withCost: boolean, mode: CostOnReceive | null) =>
      movementForm(operation, [], 'none', withCost, mode)
        .sections.flatMap((section) => section.fields)
        .map((field) => field.id);

    expect(asked('receive', true, 'suggest')).toContain('applyCost');
    for (const mode of ['average', 'last', 'manual', null] as const) {
      expect(asked('receive', true, mode)).not.toContain('applyCost');
    }
    expect(asked('receive', false, 'suggest')).not.toContain('applyCost');
    expect(asked('count', true, 'suggest')).not.toContain('applyCost');
  });

  it('sends the chosen basis only on a receipt with a cost typed, and never a value that is not one', () => {
    const values = {
      ...movementValues([]),
      productId: 'p1',
      locationId: 'l1',
      quantity: '2',
      unitCost: '12.5',
    };

    expect(movementInput('receive', { ...values, applyCost: 'last' }).applyCost).toBe('last');
    expect(movementInput('receive', { ...values, applyCost: 'average' }).applyCost).toBe('average');
    for (const applyCost of ['', 'manual', 'suggest']) {
      expect(movementInput('receive', { ...values, applyCost })).not.toHaveProperty('applyCost');
    }
    expect(
      movementInput('receive', { ...values, unitCost: '', applyCost: 'last' }),
    ).not.toHaveProperty('applyCost');
    expect(movementInput('count', { ...values, applyCost: 'last' })).not.toHaveProperty(
      'applyCost',
    );
  });
});

// docs/SPEC.md § 7, audit 2026-10-06 C challenge 9.
describe('the cost a cost reader enters for a receipt left to complete', () => {
  const asked = (mode: CostOnReceive | null) =>
    receiptCostForm(mode)
      .sections.flatMap((section) => section.fields)
      .map((field) => field.id);

  it('asks the cost, and what it does to the product only where the company leaves that to a receipt', () => {
    expect(asked('suggest')).toEqual(['unitCost', 'applyCost']);
    for (const mode of ['average', 'last', 'manual', null] as const) {
      expect(asked(mode)).toEqual(['unitCost']);
    }
  });

  it('sends the cost trimmed and a basis only when one was chosen', () => {
    expect(receiptCostInput({ ...receiptCostValues(), unitCost: ' 12.5 ' })).toEqual({
      unitCost: '12.5',
      applyCost: null,
    });
    expect(receiptCostInput({ unitCost: '12.5', applyCost: 'average' }).applyCost).toBe('average');
    expect(receiptCostInput({ unitCost: '12.5', applyCost: 'manual' }).applyCost).toBeNull();
  });
});

describe('a receipt shared over several places', () => {
  const parts = [
    { locationId: 'l2', quantity: '6' },
    { locationId: 'l1', quantity: '4.0' },
    { locationId: 'l3', quantity: '' },
  ];

  it('asks no single place: the quantity is the whole delivery and the rows say where it goes', () => {
    const fields = movementForm('receive', [], 'none', false, null, true).sections[0].fields.map(
      (field) => field.id,
    );
    expect(fields).not.toContain('locationId');
    expect(fields).toContain('quantity');
    expect(
      movementForm('receive', [], 'none', false, null).sections[0].fields.map((f) => f.id),
    ).toContain('locationId');
  });

  it('sends the rows given a quantity, each written as the API reads it, with the lot and the cost beside', () => {
    expect(
      receiptInput(
        {
          productId: ' p1 ',
          quantity: '10',
          lotCode: ' L-07 ',
          lotExpiresOn: '2027-05-31',
          unitCost: '2.5',
          applyCost: 'last',
        },
        parts,
      ),
    ).toEqual({
      productId: 'p1',
      parts: [
        { locationId: 'l2', quantity: '6' },
        { locationId: 'l1', quantity: '4' },
      ],
      lotCode: 'L-07',
      lotExpiresOn: '2027-05-31',
      unitCost: '2.5',
      applyCost: 'last',
    });
  });

  it('names no lot, date or cost choice that was not typed', () => {
    expect(receiptInput({ productId: 'p1', applyCost: 'last' }, parts)).toEqual({
      productId: 'p1',
      parts: [
        { locationId: 'l2', quantity: '6' },
        { locationId: 'l1', quantity: '4' },
      ],
    });
  });
  it('asks a receipt its supplier reference and arrival day, and its vendor only where vendors are kept', () => {
    const ids = (operation: 'receive' | 'count', vendors: boolean) =>
      movementForm(operation, [], 'none', false, null, false, vendors).sections[0].fields.map(
        (field) => field.id,
      );
    expect(ids('receive', true)).toEqual(
      expect.arrayContaining(['vendorId', 'supplierReference', 'receivedOn']),
    );
    expect(ids('receive', false)).toEqual(
      expect.arrayContaining(['supplierReference', 'receivedOn']),
    );
    expect(ids('receive', false)).not.toContain('vendorId');
    expect(ids('count', true)).not.toEqual(
      expect.arrayContaining(['vendorId', 'supplierReference', 'receivedOn']),
    );
    expect(
      movementForm('receive', [], 'none', false, null, false, true).sections[0].fields.find(
        (field) => field.id === 'vendorId',
      )?.kind,
    ).toBe('pick');
  });

  it('sends the document of a receipt trimmed, and nothing of it when empty or on another operation', () => {
    const values = {
      ...movementValues([]),
      productId: 'p1',
      locationId: 'l1',
      quantity: '2',
      vendorId: 'v1',
      supplierReference: ' BL-2210 ',
      receivedOn: '2026-10-02',
    };
    expect(movementInput('receive', values)).toMatchObject({
      vendorId: 'v1',
      supplierReference: 'BL-2210',
      receivedOn: '2026-10-02',
    });
    const none = movementInput('receive', movementValues([]));
    expect(none).not.toHaveProperty('vendorId');
    expect(none).not.toHaveProperty('supplierReference');
    expect(none).not.toHaveProperty('receivedOn');
    expect(movementInput('count', values)).not.toHaveProperty('supplierReference');
    expect(receiptInput(values, [{ locationId: 'l2', quantity: '6' }])).toMatchObject({
      vendorId: 'v1',
      supplierReference: 'BL-2210',
      receivedOn: '2026-10-02',
    });
    expect(
      receiptInput({ productId: 'p1' }, [{ locationId: 'l2', quantity: '6' }]),
    ).not.toHaveProperty('vendorId');
  });

  it('keeps the vendor, reference and day in the movements list, hidden until asked for', () => {
    const columns = MOVEMENTS_LIST.columns;
    for (const id of ['vendor', 'supplierReference', 'receivedOn']) {
      expect(columns.find((column) => column.id === id)?.defaultHidden).toBe(true);
    }
  });
});
