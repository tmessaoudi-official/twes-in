// SPDX-License-Identifier: AGPL-3.0-or-later

import { addCount } from '../shared/scan/scan-lines';
import { QUANTITY_PATTERN } from './inventory-forms';
import type { StockLocationRow, StockMovementInput, StockProductOption } from './inventory-types';

/**
 * One product — or one lot of a tracked product — counted at a location in count mode (docs/SPEC.md § 7, 2026-09-23
 * slice 8): what a scan named, and how many were found, which a person may correct by hand.
 */
export interface CountLine {
  readonly productId: string;
  readonly reference: string;
  readonly name: string;
  readonly unitDecimals: number;
  readonly tracking: StockProductOption['tracking'];
  /** The lot or serial number, from the label or typed; empty for an untracked product. */
  readonly lotCode: string;
  /** A decimal string, as the API takes a quantity. */
  readonly counted: string;
}

/** Where a scan landed on the sheet, and the sheet as it was before, to take the scan back whole. */
export interface Counted {
  readonly lines: readonly CountLine[];
  readonly index: number;
  readonly before: readonly CountLine[];
}

/** A scan onto the sheet: the same product and lot counts on, as a till does; anything else is a line of its own. */
export function countLine(
  lines: readonly CountLine[],
  product: StockProductOption,
  lotCode: string,
  pieces: number,
): Counted {
  const index = lines.findIndex(
    (line) => line.productId === product.id && line.lotCode === lotCode,
  );
  if (index !== -1) {
    const line = lines[index];
    const counted = { ...line, counted: addCount(line.counted, pieces) };
    return {
      lines: lines.map((each, at) => (at === index ? counted : each)),
      index,
      before: lines,
    };
  }
  const line: CountLine = {
    productId: product.id,
    reference: product.reference,
    name: product.name,
    unitDecimals: product.unitDecimals,
    tracking: product.tracking,
    lotCode,
    counted: String(pieces),
  };
  return { lines: [...lines, line], index: lines.length, before: lines };
}

/** The address a location's label carries (slice 8b): `…/stock/locations/<id>`. */
const LOCATION_ADDRESS =
  /\/stock\/locations\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i;

/**
 * The location a scan names: the address its label carries, or its code as the store numbers it, exactly as written.
 * Null for anything else — a product's code, or a location this company does not have.
 */
export function locationScanned(
  code: string,
  locations: readonly StockLocationRow[],
): string | null {
  const address = LOCATION_ADDRESS.exec(code.trim());
  const found = address
    ? locations.find((location) => location.id.toLowerCase() === address[1].toLowerCase())
    : locations.find((location) => location.code === code.trim());
  return found?.id ?? null;
}

const QUANTITY = new RegExp(`^(?:${QUANTITY_PATTERN})$`);

/** Whether a line can be recorded: a whole count the API takes, and a tracked product's lot. */
export function readyToRecord(line: CountLine): boolean {
  return (
    QUANTITY.test(line.counted.trim()) && (line.tracking === 'none' || line.lotCode.trim() !== '')
  );
}

/** The lines as the count movements the API records, one per product and lot at the location counted. */
export function countInputs(lines: readonly CountLine[], locationId: string): StockMovementInput[] {
  return lines.map((line) => ({
    operation: 'count',
    productId: line.productId,
    locationId,
    quantity: line.counted.trim(),
    ...(line.lotCode.trim() === '' ? {} : { lotCode: line.lotCode.trim() }),
  }));
}
