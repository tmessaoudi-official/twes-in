// SPDX-License-Identifier: AGPL-3.0-or-later

import type { AbstractControl, FormControl } from '@angular/forms';
import type { ScanOutcome } from './scan-bus';

/** The controls a document line must have for a scan to land on it. */
export interface LineFields {
  readonly productId: FormControl<string>;
  readonly unitId: FormControl<string>;
  readonly quantity: FormControl<string>;
  readonly description: FormControl<string>;
}

/**
 * A document's lines as far as a scan needs them: a typed `FormArray` of the document's own line groups is one.
 * Structural, because Angular's `FormArray` does not resolve its controls' type for a bare type parameter.
 */
export interface LineList<L extends AbstractControl> {
  readonly controls: L[];
  readonly length: number;
  at(index: number): L;
  setControl(index: number, control: L): void;
  push(control: L): void;
  removeAt(index: number): void;
}

/** What a scan asks of a document's lines: this many of this product, counted in this unit. */
export interface ScannedLine {
  readonly productId: string;
  readonly unitId: string;
  /** Whole pieces: a pack's count, times the multiplier typed before the scan. */
  readonly count: number;
}

/** Where a scan landed, and how to take it back. */
export interface ScanPlacement {
  /** The line's position when the scan landed. */
  readonly index: number;
  /** Whether the scan started a line rather than adding to one. */
  readonly added: boolean;
  /** The line's quantity once the scan landed. */
  readonly quantity: string;
  /** Takes back exactly what the scan did, and nothing another change did since. */
  readonly undo: () => void;
}

/**
 * A scan onto a document's lines, as a cashier's till does it (docs/SPEC.md § 7, 2026-09-23 09:30): the same product
 * counted in the same unit adds to its line, whatever price the line was given by hand; anything else starts a line
 * — in place of the empty one a new document opens with, when it is still empty. `fresh` builds that line with the
 * product already applied, since what a product fills in (price, taxes) is each document's own rule.
 *
 * Lots are not compared yet: no document line carries one. When lines do, the lot joins the product and the unit.
 */
export function scanIntoLines<L extends AbstractControl>(
  lines: LineList<L>,
  fields: (line: L) => LineFields,
  scanned: ScannedLine,
  fresh: () => L,
): ScanPlacement {
  const same = lines.controls.findIndex((line) => {
    const { productId, unitId } = fields(line);
    return productId.value === scanned.productId && unitId.value === scanned.unitId;
  });
  if (same !== -1) {
    const line = lines.at(same);
    const quantity = fields(line).quantity;
    const before = quantity.value;
    const after = addCount(before, scanned.count);
    quantity.setValue(after);
    line.markAsDirty();
    return {
      index: same,
      added: false,
      quantity: after,
      undo: () => {
        // Only the count this scan set is taken back: a quantity somebody typed since is theirs.
        if (quantity.value === after) quantity.setValue(before);
      },
    };
  }

  const line = fresh();
  fields(line).quantity.setValue(String(scanned.count));
  const blank = lines.controls.findIndex((each) => {
    const { productId, description } = fields(each);
    return productId.value === '' && description.value.trim() === '';
  });
  if (blank !== -1) {
    const replaced = lines.at(blank);
    lines.setControl(blank, line);
    // Marked once attached, so the document itself reads as changed and asks before it is left unsaved.
    line.markAsDirty();
    return {
      index: blank,
      added: true,
      quantity: fields(line).quantity.value,
      undo: () => {
        const at = lines.controls.indexOf(line);
        if (at !== -1) lines.setControl(at, replaced);
      },
    };
  }
  lines.push(line);
  line.markAsDirty();
  return {
    index: lines.length - 1,
    added: true,
    quantity: fields(line).quantity.value,
    undo: () => {
      const at = lines.controls.indexOf(line);
      if (at !== -1) lines.removeAt(at);
    },
  };
}

/**
 * A line's quantity with whole pieces added, keeping the decimals it had: "1.250" and two make "3.250". Worked on the
 * digits rather than on a float, so no binary rounding enters a quantity a document will print. A quantity that is
 * not a number yet counts as none.
 */
export function addCount(quantity: string, count: number): string {
  const match = /^(\d+)(?:\.(\d+))?$/.exec(quantity.trim());
  if (match === null) return String(count);
  const whole = (BigInt(match[1]) + BigInt(count)).toString();
  return match[2] === undefined ? whole : `${whole}.${match[2]}`;
}

/** What a screen says of a scan that landed on its lines: the product added, or its line's new quantity. */
export function placedOutcome(placed: ScanPlacement, name: string): ScanOutcome {
  return placed.added
    ? { kind: 'done', key: 'scan.added', params: { name }, undo: placed.undo }
    : {
        kind: 'done',
        key: 'scan.incremented',
        params: { name, quantity: placed.quantity },
        undo: placed.undo,
      };
}
