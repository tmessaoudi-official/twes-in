// SPDX-License-Identifier: AGPL-3.0-or-later

import { minus } from '../shared/documents/per-unit';
import { addCount } from '../shared/scan/scan-lines';
import { QUANTITY_PATTERN } from './inventory-forms';
import type {
  CountContext,
  StockLocationRow,
  StockMovementInput,
  StockProductOption,
} from './inventory-types';
import { sumQuantities } from './stock-quantities';

/**
 * One product — or one lot of a tracked product — counted at a location in count mode (docs/SPEC.md § 7, 2026-09-23
 * slice 8): what a scan named, where it was found, and how many, which a person may correct by hand.
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
  /** Where it was found: the location being counted when it was scanned, and where it is recorded. */
  readonly locationId: string;
}

/** Where a scan landed on the sheet, and the sheet as it was before, to take the scan back whole. */
export interface Counted {
  readonly lines: readonly CountLine[];
  readonly index: number;
  readonly before: readonly CountLine[];
}

/**
 * A scan onto the sheet at the location being counted: the same product and lot there counts on, as a till does;
 * anything else, the same product found at another location included, is a line of its own.
 */
export function countLine(
  lines: readonly CountLine[],
  product: StockProductOption,
  lotCode: string,
  pieces: number,
  locationId: string,
): Counted {
  const index = lines.findIndex(
    (line) =>
      line.productId === product.id && line.lotCode === lotCode && line.locationId === locationId,
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
    locationId,
  };
  return { lines: [...lines, line], index: lines.length, before: lines };
}

/**
 * A line a screen asked to count, « Sous zéro » on « À surveiller »: the product (and its lot) at that place, its count
 * left empty for what is really there.
 */
export function askedLine(
  product: StockProductOption,
  lotCode: string,
  locationId: string,
): CountLine {
  return {
    productId: product.id,
    reference: product.reference,
    name: product.name,
    unitDecimals: product.unitDecimals,
    tracking: product.tracking,
    lotCode,
    counted: '',
    locationId,
  };
}

/** The address a location's label carries (slice 8b): `…/stock/locations/<id>`. */
const LOCATION_ADDRESS =
  /\/stock\/locations\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i;

/** The location a scan names, or that its code names one in several establishments and none of them is in hand. */
export type ScannedLocation =
  { readonly kind: 'found'; readonly id: string } | { readonly kind: 'ambiguous' };

/**
 * The location a scan names: the address its label carries, or its code as the store numbers it, exactly as written.
 * A code is used once per establishment, so one used in several is the one in the establishment of the place `near`,
 * the place already in hand; none there, it is ambiguous, and only the label says which. Null for anything else — a
 * product's code, or a location this company does not have.
 */
export function locationScanned(
  code: string,
  locations: readonly StockLocationRow[],
  near: string | null,
): ScannedLocation | null {
  const address = LOCATION_ADDRESS.exec(code.trim());
  if (address) {
    const found = locations.find(
      (location) => location.id.toLowerCase() === address[1].toLowerCase(),
    );
    return found ? { kind: 'found', id: found.id } : null;
  }
  const named = locations.filter((location) => location.code === code.trim());
  if (named.length <= 1) return named[0] ? { kind: 'found', id: named[0].id } : null;
  const establishment = locations.find((location) => location.id === near)?.establishmentId;
  const here = named.find((location) => location.establishmentId === establishment);
  return here ? { kind: 'found', id: here.id } : { kind: 'ambiguous' };
}

const QUANTITY = new RegExp(`^(?:${QUANTITY_PATTERN})$`);

/** Whether a line can be recorded: where it was found, a whole count the API takes, and a tracked product's lot. */
export function readyToRecord(line: CountLine): boolean {
  return (
    line.locationId !== '' &&
    QUANTITY.test(line.counted.trim()) &&
    (line.tracking === 'none' || line.lotCode.trim() !== '')
  );
}

/** The lines as the count movements the API records, one per line, each at the location it was found. */
export function countInputs(lines: readonly CountLine[]): StockMovementInput[] {
  return lines.map((line) => ({
    operation: 'count',
    productId: line.productId,
    locationId: line.locationId,
    quantity: line.counted.trim(),
    ...(line.lotCode.trim() === '' ? {} : { lotCode: line.lotCode.trim() }),
  }));
}

/** The sheet by location, in the order the locations were first counted; each line keeps its place on the sheet. */
export function countGroups(
  lines: readonly CountLine[],
): { locationId: string; entries: { line: CountLine; index: number }[] }[] {
  const groups = new Map<string, { line: CountLine; index: number }[]>();
  lines.forEach((line, index) => {
    const entries = groups.get(line.locationId) ?? [];
    entries.push({ line, index });
    groups.set(line.locationId, entries);
  });
  return [...groups].map(([locationId, entries]) => ({ locationId, entries }));
}

/** What a count at one place would change, said on its line before anything is recorded. */
export interface CountEffect {
  /** What is recorded there now: of the lot named, else of every lot there. */
  readonly expected: string;
  /** The count less what is expected, signed; null while the count is not a quantity yet. */
  readonly difference: string | null;
  readonly direction: 'up' | 'down' | 'same' | null;
  /** The product's stock at every other place that holds some, its lots together. */
  readonly elsewhere: readonly { readonly locationId: string; readonly quantity: string }[];
  /**
   * Nothing of the product is recorded at this place and it is not the product's home, while the product is kept
   * elsewhere: recording adds the count here and takes nothing from where it was. Said, never refused, since finding
   * goods in a new place is a real count.
   */
  readonly stray: boolean;
  /** Where the product lives, which a stray count names. */
  readonly homeLocationIds: readonly string[];
  /** The lot a count would open, the product never having had it; null when the lot is known or none is named. */
  readonly newLot: string | null;
}

/** What is counted, where, and of which lot. */
export interface Counting {
  readonly locationId: string;
  readonly lotCode: string;
  readonly counted: string;
  readonly tracking: StockProductOption['tracking'];
}

/**
 * What a count would change, from what the API says of the product: what is expected here, the difference, what is
 * elsewhere, and whether the place or the lot is new to the product. Null while there is no place to say it of. The lot
 * is matched exactly as the API keeps it, its typed ends trimmed and nothing folded.
 */
export function countEffect(
  context: CountContext,
  locations: readonly StockLocationRow[],
  counting: Counting,
): CountEffect | null {
  const place = counting.locationId;
  if (place === '') return null;
  const lot = counting.tracking === 'none' ? '' : counting.lotCode.trim();
  const here = context.places.filter((row) => row.locationId === place);
  const expected = sumQuantities(
    here.filter((row) => lot === '' || row.lotCode === lot).map((row) => row.quantity),
  );
  const counted = counting.counted.trim();
  const difference = QUANTITY.test(counted) ? minus(counted, expected) : null;

  const totals = new Map<string, string[]>();
  for (const row of context.places) {
    if (row.locationId === place) continue;
    totals.set(row.locationId, [...(totals.get(row.locationId) ?? []), row.quantity]);
  }
  const elsewhere = [...totals]
    .map(([locationId, quantities]) => ({ locationId, quantity: sumQuantities(quantities) }))
    .filter((each) => !isNought(each.quantity));

  const heldHere = !isNought(sumQuantities(here.map((row) => row.quantity)));
  const stray =
    !heldHere &&
    !atHome(place, context.homeLocationIds, locations) &&
    (elsewhere.length > 0 || context.homeLocationIds.length > 0);
  const newLot = lot !== '' && !context.places.some((row) => row.lotCode === lot) ? lot : null;

  return {
    expected,
    difference,
    direction: difference === null ? null : directionOf(difference),
    elsewhere,
    stray,
    homeLocationIds: context.homeLocationIds,
    newLot,
  };
}

/** Whether a place is one the product lives at, or under one: a rack answers for its bins. */
function atHome(
  place: string,
  homes: readonly string[],
  locations: readonly StockLocationRow[],
): boolean {
  const parents = new Map(locations.map((location) => [location.id, location.parentId]));
  const seen = new Set<string>();
  for (let at: string | null | undefined = place; at; at = parents.get(at)) {
    if (homes.includes(at)) return true;
    if (seen.has(at)) return false;
    seen.add(at);
  }
  return false;
}

function isNought(quantity: string): boolean {
  return /^-?0*(?:\.0*)?$/.test(quantity);
}

function directionOf(difference: string): 'up' | 'down' | 'same' {
  if (isNought(difference)) return 'same';
  return difference.startsWith('-') ? 'down' : 'up';
}
