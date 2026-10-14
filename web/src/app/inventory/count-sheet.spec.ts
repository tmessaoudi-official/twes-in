// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import {
  countInputs,
  countLine,
  locationScanned,
  readyToRecord,
  type CountLine,
} from './count-sheet';
import type { StockLocationRow, StockProductOption } from './inventory-types';

const screws: StockProductOption = {
  id: 'p1',
  reference: 'VIS-6',
  name: 'Vis 6x40',
  unitCode: 'C62',
  unitDecimals: 0,
  homeLocationId: null,
  tracking: 'none',
};
const glue: StockProductOption = {
  ...screws,
  id: 'p3',
  reference: 'COL-1',
  name: 'Colle',
  tracking: 'lot',
};

const location = (id: string, code: string): StockLocationRow => ({
  id,
  establishmentId: 'e1',
  parentId: null,
  kind: 'zone',
  code,
  name: `Zone ${code}`,
  isDefault: false,
  childCount: 0,
  movementCount: 0,
});

describe('the count sheet', () => {
  it('counts a product scanned again on its line, and a lot of its own on another', () => {
    let lines: readonly CountLine[] = [];
    lines = countLine(lines, screws, '', 1, 'l1').lines;
    lines = countLine(lines, screws, '', 12, 'l1').lines;
    lines = countLine(lines, glue, 'L-07', 1, 'l1').lines;
    const placed = countLine(lines, glue, 'L-08', 1, 'l1');

    expect(placed.lines.map((line) => [line.reference, line.lotCode, line.counted])).toEqual([
      ['VIS-6', '', '13'],
      ['COL-1', 'L-07', '1'],
      ['COL-1', 'L-08', '1'],
    ]);
    expect(placed.index).toBe(2);
    // What it was before the scan, to take the scan back whole.
    expect(placed.before).toBe(lines);
  });

  it('keeps the decimals a counted quantity was typed with', () => {
    const typed = [{ ...countLine([], screws, '', 1, 'l1').lines[0], counted: '2.500' }];
    expect(countLine(typed, screws, '', 2, 'l1').lines[0].counted).toBe('4.500');
  });

  it('knows a location by the address its label carries, or by its code, and nothing else', () => {
    const locations = [
      location('0192a0b1-0000-7000-8000-000000000001', 'A-R1'),
      location('l2', 'B'),
    ];

    expect(
      locationScanned(
        'https://twes.example/stock/locations/0192a0b1-0000-7000-8000-000000000001',
        locations,
      ),
    ).toBe('0192a0b1-0000-7000-8000-000000000001');
    expect(locationScanned('A-R1', locations)).toBe('0192a0b1-0000-7000-8000-000000000001');
    expect(locationScanned('a-r1', locations)).toBeNull();
    expect(locationScanned('3017620422003', locations)).toBeNull();
    // An address of a location this company does not have is not one of its locations.
    expect(
      locationScanned(
        'https://twes.example/stock/locations/0192a0b1-0000-7000-8000-00000000000f',
        locations,
      ),
    ).toBeNull();
  });

  it('records a line once it has a whole count, and a tracked product only with its lot', () => {
    const [plain] = countLine([], screws, '', 3, 'l1').lines;
    const [lotless] = countLine([], glue, '', 1, 'l1').lines;
    const [lotted] = countLine([], glue, 'L-07', 1, 'l1').lines;

    expect([plain, lotless, lotted].map(readyToRecord)).toEqual([true, false, true]);
    expect(readyToRecord({ ...plain, counted: '' })).toBe(false);
    expect(readyToRecord({ ...plain, counted: '1.2345' })).toBe(false);
    expect(readyToRecord({ ...plain, counted: '0' })).toBe(true);

    expect(countInputs([plain, lotted])).toEqual([
      { operation: 'count', productId: 'p1', locationId: 'l1', quantity: '3' },
      { operation: 'count', productId: 'p3', locationId: 'l1', quantity: '1', lotCode: 'L-07' },
    ]);
  });

  // A line once took the location chosen last, and was recorded there rather than where it was found.
  it('counts a product found at another location on a line of its own, recorded where it was found', () => {
    let lines: readonly CountLine[] = [];
    lines = countLine(lines, screws, '', 2, 'l1').lines;
    lines = countLine(lines, screws, '', 3, 'l2').lines;
    lines = countLine(lines, screws, '', 1, 'l1').lines;

    expect(lines.map((line) => [line.locationId, line.counted])).toEqual([
      ['l1', '3'],
      ['l2', '3'],
    ]);
    expect(countInputs(lines).map((input) => input.locationId)).toEqual(['l1', 'l2']);
    // A line scanned before any location was known cannot be recorded anywhere.
    expect(readyToRecord({ ...lines[0], locationId: '' })).toBe(false);
  });
});
