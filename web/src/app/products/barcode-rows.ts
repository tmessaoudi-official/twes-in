// SPDX-License-Identifier: AGPL-3.0-or-later

import type { BarcodeRole, ProductBarcode } from './products-types';

/** The API's own ceiling on one scan (`BarcodeLine::QUANTITY_MAX`). */
export const BARCODE_QUANTITY_MAX = 1_000_000;

/** What a row can be wrong about, as `products.barcodes.problems.<key>` says it. */
export type BarcodeProblem =
  | 'code_required'
  | 'code_shape'
  | 'check_digit'
  | 'quantity_range'
  | 'pack_many'
  | 'supplier_required';

const GTIN_LENGTHS = [8, 12, 13, 14];

function claimsGtin(code: string): boolean {
  return GTIN_LENGTHS.includes(code.length) && /^[0-9]+$/.test(code);
}

/** The GS1 check digit rule, shared by every GTIN length: weights 3 and 1 alternating from the check digit. */
function checkDigitHolds(code: string): boolean {
  const digits = [...code].map(Number);
  const check = digits.pop() ?? -1;
  const sum = digits
    .reverse()
    .reduce((total, digit, place) => total + digit * (place % 2 === 0 ? 3 : 1), 0);
  return check === (10 - (sum % 10)) % 10;
}

/**
 * What the API compares a code on (`Barcode::keyOf`): a GTIN right-justified on fourteen digits, so a UPC-A and its
 * EAN-13 are one code; anything else as printed. The screen uses it only to say a code is already in the list
 * before asking; the API stays the judge.
 */
export function barcodeKey(code: string): string {
  const trimmed = code.trim();
  return claimsGtin(trimmed) && checkDigitHolds(trimmed) ? trimmed.padStart(14, '0') : trimmed;
}

/**
 * A code read by a scanner or typed then Enter, added to the list: the product's unit code when it has none yet,
 * an internal code after that, which the person turns into a pack or a supplier's code with one choice. A code the
 * list already holds is not added twice; its row is named so the screen can point at it.
 */
export function addScanned(
  rows: readonly ProductBarcode[],
  scanned: string,
): { rows: ProductBarcode[]; duplicate: number | null } {
  const code = scanned.trim();
  if (code === '') return { rows: [...rows], duplicate: null };
  const key = barcodeKey(code);
  const duplicate = rows.findIndex((row) => barcodeKey(row.code) === key);
  if (duplicate >= 0) return { rows: [...rows], duplicate };
  const role: BarcodeRole = rows.some((row) => row.role === 'unit') ? 'internal' : 'unit';
  return { rows: [...rows, { role, code, quantity: 1, supplierId: null }], duplicate: null };
}

/** The row under another role, with what that role allows: a unit is one piece, a pack several, a supplier named. */
export function withRole(row: ProductBarcode, role: BarcodeRole): ProductBarcode {
  switch (role) {
    case 'unit':
      return { ...row, role, quantity: 1, supplierId: null };
    case 'pack':
      return { ...row, role, quantity: Math.max(2, row.quantity), supplierId: null };
    case 'supplier':
      return { ...row, role };
    case 'internal':
      return { ...row, role, supplierId: null };
  }
}

/** The first thing wrong with a row, in the order the API would say it, or null. */
export function rowProblem(row: ProductBarcode): BarcodeProblem | null {
  const code = row.code.trim();
  if (code === '') return 'code_required';
  if (!/^[\x21-\x7E]{1,64}$/.test(code)) return 'code_shape';
  if (claimsGtin(code) && !checkDigitHolds(code)) return 'check_digit';
  if (!Number.isInteger(row.quantity) || row.quantity < 1 || row.quantity > BARCODE_QUANTITY_MAX) {
    return 'quantity_range';
  }
  if (row.role === 'pack' && row.quantity < 2) return 'pack_many';
  if (row.role === 'supplier' && row.supplierId === null) return 'supplier_required';
  return null;
}

/** Whether two lists say the same codes, whatever their order: what decides that there is something to save. */
export function sameCodes(a: readonly ProductBarcode[], b: readonly ProductBarcode[]): boolean {
  const said = (rows: readonly ProductBarcode[]): string[] =>
    rows
      .map((row) =>
        JSON.stringify([
          barcodeKey(row.code),
          row.code.trim(),
          row.role,
          row.quantity,
          row.supplierId,
        ]),
      )
      .sort();
  return JSON.stringify(said(a)) === JSON.stringify(said(b));
}
