// SPDX-License-Identifier: AGPL-3.0-or-later

import { PRICE_PATTERN, QUANTITY_PATTERN, plainDecimal } from './purchase-order-forms';
import type {
  CostBasis,
  PurchaseOrderLine,
  ReceiptInput,
  ReceiptLine,
  Receiving,
} from './purchases-types';

/** A supplier reference as the API keeps it, and a lot code. */
export const SUPPLIER_REFERENCE_MAX = 60;
export const LOT_CODE_MAX = 40;

/**
 * One order line as the receipt dialog holds it: how much came, where it goes, and the lot it came in. A product kept
 * by serial number is received piece by piece: its serial numbers are typed one per line, as a scanner writes them,
 * and each becomes a receipt line of one.
 */
export interface ReceiveRow {
  readonly line: PurchaseOrderLine;
  readonly quantity: string;
  readonly locationId: string;
  readonly lotCode: string;
  readonly lotExpiresOn: string;
  readonly serials: string;
  readonly unitCost: string;
}

/** What is wrong with a row, the first thing found, or null when it can be sent or is left out. */
export type RowProblem =
  | 'quantity'
  | 'over'
  | 'place'
  | 'lot'
  | 'lot_length'
  | 'serials_over'
  | 'serials_repeated'
  | 'serial_length'
  | 'unit_cost';

/**
 * A row per line still expected, opening on everything outstanding going to the line's place (its product's home, or
 * the establishment's default) at the price the order said: the usual receipt is the whole delivery, checked.
 */
export function receiveRows(
  lines: readonly PurchaseOrderLine[],
  receiving: Receiving,
): ReceiveRow[] {
  const fallback = receiving.places.find((place) => place.isDefault)?.id ?? '';
  return lines
    .filter((line) => thousandths(line.outstanding) > 0n)
    .map((line) => ({
      line,
      quantity: line.productTracking === 'serial' ? '' : plainDecimal(line.outstanding),
      locationId: receiving.homes[line.id] ?? fallback,
      lotCode: '',
      lotExpiresOn: '',
      serials: '',
      unitCost: line.unitPriceNet === null ? '' : plainDecimal(line.unitPriceNet),
    }));
}

/** The serial numbers typed, one per line, blank lines left out. */
export function serialsOf(row: ReceiveRow): string[] {
  return row.serials
    .split(/\r?\n/)
    .map((serial) => serial.trim())
    .filter((serial) => serial !== '');
}

/** Whether a row brings anything: a row left at nothing is not part of this receipt. */
export function bringsGoods(row: ReceiveRow): boolean {
  if (row.line.productTracking === 'serial') return serialsOf(row).length > 0;
  return row.quantity.trim() !== '' && thousandths(row.quantity) !== 0n;
}

export function rowProblem(row: ReceiveRow, seesCost: boolean): RowProblem | null {
  if (!bringsGoods(row)) {
    return row.quantity.trim() === '' || isQuantity(row.quantity) ? null : 'quantity';
  }
  if (row.locationId === '') return 'place';
  if (row.line.productTracking === 'serial') {
    const serials = serialsOf(row);
    if (new Set(serials).size !== serials.length) return 'serials_repeated';
    if (serials.some((serial) => serial.length > LOT_CODE_MAX)) return 'serial_length';
    if (BigInt(serials.length) * 1000n > thousandths(row.line.outstanding)) return 'serials_over';
  } else {
    if (!isQuantity(row.quantity)) return 'quantity';
    if (thousandths(row.quantity) > thousandths(row.line.outstanding)) return 'over';
    if (row.line.productTracking === 'lot') {
      if (row.lotCode.trim() === '') return 'lot';
      if (row.lotCode.trim().length > LOT_CODE_MAX) return 'lot_length';
    }
  }
  if (seesCost && row.unitCost.trim() !== '' && !matches(PRICE_PATTERN, row.unitCost.trim())) {
    return 'unit_cost';
  }
  return null;
}

/**
 * The receipt as the API takes it, and for each of its lines the row it came from, so a refusal naming a line is
 * shown on its row. A cost is sent only by whoever may read costs; the cost basis only where the company leaves it to
 * the person and a cost was typed.
 */
export function receiptInput(
  receivedOn: string,
  supplierReference: string,
  apply: CostBasis | null,
  rows: readonly ReceiveRow[],
  seesCost: boolean,
): { input: ReceiptInput; sources: number[] } {
  const lines: ReceiptLine[] = [];
  const sources: number[] = [];
  rows.forEach((row, index) => {
    if (!bringsGoods(row)) return;
    const unitCost = seesCost && row.unitCost.trim() !== '' ? row.unitCost.trim() : null;
    const base = {
      orderLineId: row.line.id,
      locationId: row.locationId,
      lotExpiresOn: null,
      unitCost,
    };
    if (row.line.productTracking === 'serial') {
      for (const serial of serialsOf(row)) {
        lines.push({ ...base, quantity: '1', lotCode: serial });
        sources.push(index);
      }
      return;
    }
    const lot = row.line.productTracking === 'lot' ? row.lotCode.trim() : '';
    lines.push({
      ...base,
      quantity: row.quantity.trim(),
      lotCode: lot === '' ? null : lot,
      lotExpiresOn: lot === '' || row.lotExpiresOn === '' ? null : row.lotExpiresOn,
    });
    sources.push(index);
  });
  const reference = supplierReference.trim();
  const costed = lines.some((line) => line.unitCost !== null);
  return {
    input: {
      receivedOn,
      supplierReference: reference === '' ? null : reference,
      apply: costed ? apply : null,
      lines,
    },
    sources,
  };
}

/** A refused field of the receipt (`lines[3].lotCode`) as the row it came from and the field there. */
export function receiptRefusal(
  field: string,
  sources: readonly number[],
): { row: number | null; field: string } {
  const match = /^lines\[(\d+)\]\.?(.*)$/.exec(field);
  if (match === null) return { row: null, field };
  return { row: sources[Number(match[1])] ?? null, field: match[2] === '' ? 'line' : match[2]! };
}

function isQuantity(value: string): boolean {
  return matches(QUANTITY_PATTERN, value.trim());
}

function matches(pattern: string, value: string): boolean {
  return new RegExp(`^(?:${pattern})$`).test(value);
}

/**
 * A quantity in thousandths, as a whole number: two quantities compare without a float rounding either. Anything that
 * is not a quantity counts as nothing, which `rowProblem` reports before comparing.
 */
function thousandths(value: string): bigint {
  const trimmed = value.trim();
  if (!matches(QUANTITY_PATTERN, trimmed)) return 0n;
  const [units = '0', fraction = ''] = trimmed.split('.');
  return BigInt(units) * 1000n + BigInt(fraction.padEnd(3, '0'));
}
