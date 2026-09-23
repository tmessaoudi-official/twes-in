// SPDX-License-Identifier: AGPL-3.0-or-later

import { FormArray, FormControl, FormGroup } from '@angular/forms';
import { describe, expect, it } from 'vitest';
import { addCount, type LineFields, placedOutcome, scanIntoLines } from './scan-lines';

type Line = FormGroup<LineFields & { note: FormControl<string> }>;

function line(productId: string, unitId: string, quantity: string, description = productId): Line {
  return new FormGroup({
    productId: new FormControl(productId, { nonNullable: true }),
    unitId: new FormControl(unitId, { nonNullable: true }),
    quantity: new FormControl(quantity, { nonNullable: true }),
    description: new FormControl(description, { nonNullable: true }),
    note: new FormControl('', { nonNullable: true }),
  });
}

const fields = (each: Line): LineFields => each.controls;

function rows(lines: FormArray<Line>): string[] {
  return lines.controls.map(
    (each) =>
      `${each.controls.productId.value}/${each.controls.unitId.value}×${each.controls.quantity.value}`,
  );
}

describe('scanIntoLines', () => {
  it('adds to the line of the same product in the same unit, whatever its price', () => {
    const lines = new FormArray([line('coffee', 'kg', '2'), line('ink', 'pc', '1')]);

    const placed = scanIntoLines(
      lines,
      fields,
      { productId: 'coffee', unitId: 'kg', count: 1 },
      () => line('coffee', 'kg', '1'),
    );

    expect(rows(lines)).toEqual(['coffee/kg×3', 'ink/pc×1']);
    expect(placed).toMatchObject({ added: false, quantity: '3', index: 0 });
    expect(lines.at(0).dirty).toBe(true);
  });

  it('adds a line for the same product in another unit, and a pack enters its count', () => {
    const lines = new FormArray([line('screws', 'pc', '5')]);

    const placed = scanIntoLines(
      lines,
      fields,
      { productId: 'screws', unitId: 'box', count: 12 },
      () => line('screws', 'box', '1'),
    );

    expect(rows(lines)).toEqual(['screws/pc×5', 'screws/box×12']);
    expect(placed).toMatchObject({ added: true, quantity: '12', index: 1 });
    // Attached before it is marked, so the document reads as changed, not only the line.
    expect(lines.dirty).toBe(true);
  });

  it('fills the empty line a new document starts with rather than leaving it blank', () => {
    const lines = new FormArray([line('', 'pc', '1', '  ')]);

    scanIntoLines(lines, fields, { productId: 'coffee', unitId: 'kg', count: 2 }, () =>
      line('coffee', 'kg', '1'),
    );

    expect(rows(lines)).toEqual(['coffee/kg×2']);
  });

  it('keeps the decimals a line already had when adding whole pieces to it', () => {
    expect(addCount('1.250', 2)).toBe('3.250');
    expect(addCount('2', 12)).toBe('14');
    expect(addCount('0.5', 1)).toBe('1.5');
    expect(addCount('', 3)).toBe('3');
    expect(addCount('abc', 3)).toBe('3');
  });

  it('undoes exactly what it did: a count taken back, an added line removed, a filled line emptied', () => {
    const empty = line('', 'pc', '1', '');
    const lines = new FormArray([line('coffee', 'kg', '2'), empty]);
    const fresh = () => line('ink', 'pc', '1');

    const incremented = scanIntoLines(
      lines,
      fields,
      { productId: 'coffee', unitId: 'kg', count: 1 },
      fresh,
    );
    const filled = scanIntoLines(
      lines,
      fields,
      { productId: 'ink', unitId: 'pc', count: 1 },
      fresh,
    );
    const added = scanIntoLines(lines, fields, { productId: 'glue', unitId: 'pc', count: 1 }, () =>
      line('glue', 'pc', '1'),
    );
    expect(rows(lines)).toEqual(['coffee/kg×3', 'ink/pc×1', 'glue/pc×1']);

    added.undo();
    filled.undo();
    incremented.undo();

    expect(rows(lines)).toEqual(['coffee/kg×2', '/pc×1']);
    expect(lines.at(1)).toBe(empty);
  });

  it('undoes a line it added even after another line was removed above it', () => {
    const lines = new FormArray([line('coffee', 'kg', '2')]);
    const added = scanIntoLines(lines, fields, { productId: 'ink', unitId: 'pc', count: 1 }, () =>
      line('ink', 'pc', '1'),
    );
    lines.removeAt(0);

    added.undo();

    expect(rows(lines)).toEqual([]);
  });

  it('says what a scan did, and names the product with its customer price for a paired phone', () => {
    const undo = () => undefined;
    const product = { name: 'Nutella', unitPrice: '12.500' };

    expect(placedOutcome({ index: 0, added: true, quantity: '1', undo }, product)).toEqual({
      kind: 'done',
      key: 'scan.added',
      params: { name: 'Nutella' },
      product,
      undo,
    });
    expect(placedOutcome({ index: 0, added: false, quantity: '3', undo }, product)).toEqual({
      kind: 'done',
      key: 'scan.incremented',
      params: { name: 'Nutella', quantity: '3' },
      product,
      undo,
    });
  });
});
