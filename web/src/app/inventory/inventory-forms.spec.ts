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
  movementInput,
  movementListRows,
  movementValues,
  STOCK_LIST,
  stockListRows,
} from './inventory-forms';
import type {
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
    recordedBy: null,
    at: '2026-09-15T09:00:00+00:00',
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
    );

    expect(
      rows.map((row) => [row.productReference, row.unitDecimals, row.negative, row.locationLabel]),
    ).toEqual([
      ['ART-1', 0, true, '000 — Siège'],
      ['ART-2', 3, false, '000 — Siège'],
      ['ART-9', 3, false, '000 — Siège'],
    ]);
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
    ]);
    expect(MOVEMENTS_LIST.columns.map((column) => column.id)).toEqual([
      'at',
      'product',
      'location',
      'kind',
      'quantity',
      'source',
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
    });
    expect(movementValues([])).toEqual({
      productId: '',
      locationId: '',
      toLocationId: '',
      quantity: '',
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
    // The field is on the body only for a move: sending an empty one on a receipt would be a claim about a
    // location that was never chosen, which the API answers 422 to.
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
