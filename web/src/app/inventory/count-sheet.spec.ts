// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import {
  countEffect,
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
  version: 1,
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
        null,
      ),
    ).toEqual({ kind: 'found', id: '0192a0b1-0000-7000-8000-000000000001' });
    expect(locationScanned('A-R1', locations, null)).toEqual({
      kind: 'found',
      id: '0192a0b1-0000-7000-8000-000000000001',
    });
    expect(locationScanned('a-r1', locations, null)).toBeNull();
    expect(locationScanned('3017620422003', locations, null)).toBeNull();
    // An address of a location this company does not have is not one of its locations.
    expect(
      locationScanned(
        'https://twes.example/stock/locations/0192a0b1-0000-7000-8000-00000000000f',
        locations,
        null,
      ),
    ).toBeNull();
  });

  it('takes a code another establishment also uses where the place in hand is, and none when that names neither', () => {
    // A code is used once per establishment: another shop may hold an « R1 » too, listed first here.
    const locations = [
      { ...location('l9', 'R1'), establishmentId: 'e2' },
      location('l1', 'R1'),
      location('l5', 'B'),
      { ...location('l7', 'C'), establishmentId: 'e2' },
      { ...location('l8', 'D'), establishmentId: 'e3' },
    ];

    expect(locationScanned('R1', locations, 'l5')).toEqual({ kind: 'found', id: 'l1' });
    expect(locationScanned('R1', locations, 'l7')).toEqual({ kind: 'found', id: 'l9' });
    expect(locationScanned('R1', locations, null)).toEqual({ kind: 'ambiguous' });
    expect(locationScanned('R1', locations, 'l8')).toEqual({ kind: 'ambiguous' });
    expect(locationScanned('R1', locations, 'unknown')).toEqual({ kind: 'ambiguous' });
    // A code used once is that place, whichever establishment is in hand; its label always is.
    expect(locationScanned('C', locations, 'l5')).toEqual({ kind: 'found', id: 'l7' });
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

describe('what a count would change', () => {
  const rack = location('rack', 'R1');
  const bin = { ...location('bin', 'R1-1'), parentId: 'rack' };
  const shelf = location('shelf', 'R2');
  const empty = location('empty', 'R3');
  const places = [rack, bin, shelf, empty];
  const screwsKept = {
    productId: 'p1',
    places: [
      { locationId: 'rack', lotCode: null, quantity: '4.000' },
      { locationId: 'shelf', lotCode: null, quantity: '10.000' },
    ],
    homeLocationIds: ['rack'],
  };
  const glueKept = {
    productId: 'p3',
    places: [
      { locationId: 'shelf', lotCode: 'L-1', quantity: '3.000' },
      { locationId: 'shelf', lotCode: 'L-2', quantity: '0.000' },
    ],
    homeLocationIds: [],
  };
  const counting = { locationId: 'rack', lotCode: '', counted: '6', tracking: 'none' as const };

  it('says what is expected here, the difference, and what is elsewhere', () => {
    expect(countEffect(screwsKept, places, counting)).toEqual({
      expected: '4.000',
      difference: '2.000',
      direction: 'up',
      elsewhere: [{ locationId: 'shelf', quantity: '10.000' }],
      stray: false,
      homeLocationIds: ['rack'],
      newLot: null,
    });
    expect(countEffect(screwsKept, places, { ...counting, counted: '1' })?.direction).toBe('down');
    expect(countEffect(screwsKept, places, { ...counting, counted: '4' })?.direction).toBe('same');
    expect(countEffect(screwsKept, places, { ...counting, counted: '' })?.difference).toBeNull();
  });

  it('warns of a count where nothing is kept and the product does not live', () => {
    const there = countEffect(screwsKept, places, { ...counting, locationId: 'empty' });
    expect(there?.stray).toBe(true);
    expect(there?.expected).toBe('0.000');
    expect(there?.elsewhere.map((each) => each.locationId)).toEqual(['rack', 'shelf']);
    // A bin under the product's rack is at home: counting goods there is no stray.
    expect(countEffect(screwsKept, places, { ...counting, locationId: 'bin' })?.stray).toBe(false);
    // A product never kept anywhere and living nowhere is a first count, not a stray one.
    const first = { productId: 'p9', places: [], homeLocationIds: [] };
    expect(countEffect(first, places, { ...counting, locationId: 'empty' })?.stray).toBe(false);
  });

  it('counts a lot against that lot, and names a lot the product never had', () => {
    const lots = { ...counting, locationId: 'shelf', tracking: 'lot' as const };
    expect(countEffect(glueKept, places, { ...lots, lotCode: 'L-1' })).toMatchObject({
      expected: '3.000',
      newLot: null,
    });
    // An emptied lot is still the product's own.
    expect(countEffect(glueKept, places, { ...lots, lotCode: ' L-2 ' })?.newLot).toBeNull();
    expect(countEffect(glueKept, places, { ...lots, lotCode: 'l-1' })?.newLot).toBe('l-1');
    expect(countEffect(glueKept, places, { ...lots, lotCode: 'L-9' })).toMatchObject({
      expected: '0.000',
      newLot: 'L-9',
      stray: false,
    });
  });

  it('says nothing before the place is known', () => {
    expect(countEffect(screwsKept, places, { ...counting, locationId: '' })).toBeNull();
  });
});
