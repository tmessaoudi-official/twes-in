// SPDX-License-Identifier: AGPL-3.0-or-later

/** Why the API refused, as the stock screens translate it. */
export type InventoryError =
  'network' | 'not_found' | 'code_taken' | 'in_use' | 'invalid' | 'level_taken';

/** Where stock is kept, from the whole site down to one bin (docs/SPEC.md § 7, 2026-09-14). */
export type StockLocationKind = 'site' | 'building' | 'floor' | 'zone' | 'rack' | 'bin';
export const STOCK_LOCATION_KINDS: readonly StockLocationKind[] = [
  'site',
  'building',
  'floor',
  'zone',
  'rack',
  'bin',
];

/**
 * The kinds that can be a rectangle on a floor plan (docs/SPEC.md § 7, 2026-09-21, decision 2). A bin is not among
 * them: it is placed in its rack's front view, by column and level, and has no x, y on the ground. The API refuses it
 * too — this list is what the screen offers, not what makes it true.
 */
export const DRAWABLE_STOCK_LOCATION_KINDS: readonly StockLocationKind[] =
  STOCK_LOCATION_KINDS.filter((kind) => kind !== 'bin');

export type StockMovementKind = 'in' | 'out' | 'adjustment';
export const STOCK_MOVEMENT_KINDS: readonly StockMovementKind[] = ['in', 'out', 'adjustment'];

export type StockSourceType = 'receipt' | 'count' | 'move' | 'delivery_note';
export const STOCK_SOURCE_TYPES: readonly StockSourceType[] = [
  'receipt',
  'count',
  'move',
  'delivery_note',
];

/** What a person records: goods received, what a count found on the shelf, or goods moved to another location. */
export type StockOperation = 'receive' | 'count' | 'move';

export interface StockLocationRow {
  id: string;
  establishmentId: string;
  /** Null for an establishment's default location, which sits at the top of its tree. */
  parentId: string | null;
  kind: StockLocationKind;
  code: string;
  name: string;
  isDefault: boolean;
  childCount: number;
  movementCount: number;
}

/** A null parent places a new location under its establishment's default one. */
export type StockLocationInput = Pick<
  StockLocationRow,
  'establishmentId' | 'parentId' | 'kind' | 'code' | 'name'
>;

/** What the API writes a quantity with when it says nothing else: three decimals, as the column holds them. */
export const API_DECIMALS = 3;

/** The sorts the API answers for a stock list. */
export type StockSortKey = 'reference' | 'product' | 'location' | 'quantity';

/** One page of the stock list as the API groups, searches, narrows and sorts it (docs/SPEC.md § 7). */
export interface StockSearch {
  /** Numbered from 1. */
  page: number;
  itemsPerPage: number;
  /** Words found in the product's reference or name or the location's code or name; empty finds all. */
  q: string;
  locationId: string | null;
  establishmentId: string | null;
  order: { key: StockSortKey; direction: 'asc' | 'desc' } | null;
}

/** The sorts the API answers for a movements list. */
export type StockMovementSortKey =
  'movedAt' | 'product' | 'location' | 'kind' | 'quantity' | 'source';

/**
 * One page of the movements list as the API narrows, sorts and pages it (docs/SPEC.md § 7, row 55 (b)). Every filter
 * the screen offers is here: the list shows the page it was sent, so one the API did not answer would narrow that
 * page alone and read as the whole result.
 */
export interface StockMovementSearch {
  /** Numbered from 1. */
  page: number;
  itemsPerPage: number;
  /** Words found in the product's reference or name or the location's code or name; empty finds all. */
  q: string;
  productId: string | null;
  locationId: string | null;
  kind: StockMovementKind | null;
  sourceType: StockSourceType | null;
  order: { key: StockMovementSortKey; direction: 'asc' | 'desc' } | null;
}

/** What is on hand of one product at one location: the sum of its movements, which may fall below zero. */
export interface StockLevelRow {
  /** The product and the location together: a row is the pair, and neither alone names it. */
  id: string;
  productId: string;
  productReference: string;
  productName: string;
  unitCode: string;
  /** How many decimals that unit counts in, so a row is shown as its unit counts without the whole catalogue. */
  unitDecimals: number;
  locationId: string;
  locationCode: string;
  locationName: string;
  establishmentId: string;
  /** A signed decimal string at three decimals, "-2.000". */
  quantity: string;
}

export interface StockMovementRow {
  id: string;
  productId: string;
  /** What the movement moved, as the API names it: a product no longer offered still has its movements. */
  productReference: string;
  productName: string;
  unitCode: string;
  /** How many decimals that unit counts in, so a row is shown as its unit counts without the whole catalogue. */
  unitDecimals: number;
  locationId: string;
  locationCode: string;
  locationName: string;
  kind: StockMovementKind;
  /** Signed: what came in is positive, what left negative, a count's difference either. */
  quantity: string;
  sourceType: StockSourceType;
  sourceId: string | null;
  recordedBy: string | null;
  at: string;
}

export interface StockMovementInput {
  operation: StockOperation;
  productId: string;
  /** Where the goods are; for a move, where they leave from. */
  locationId: string;
  /** Where a move puts them; absent for anything else. */
  toLocationId?: string;
  quantity: string;
}

/** One stocked product as the picker answers it. */
export interface StockProductOption {
  id: string;
  reference: string;
  name: string;
  unitCode: string;
  unitDecimals: number;
  /**
   * Where this product normally lives (docs/SPEC.md row 101), when one establishment's home is the only one it has.
   * A product at home in two buildings comes without it: a picker knows which product was chosen, not which site the
   * goods are arriving at, and a wrong shelf proposed is worse than none because it is accepted without being read.
   */
  homeLocationId: string | null;
}

export interface StockEstablishmentOption {
  id: string;
  code: string;
  name: string;
}

/**
 * One floor a company's stock is drawn on (docs/SPEC.md row 83, decision 1): the floor IS the plan, so a site, a
 * building and a storey are not rectangles on it — zones and racks are.
 */
export interface StockFloorRow {
  id: string;
  /** The establishment whose place this floor is; a revision keeps the same one. */
  establishmentId: string;
  name: string;
  /** Which storey, the ground being 0; it orders the tabs. */
  level: number;
  imageFileId: string | null;
  /** What the whole image spans on the ground, in metres; without it the image cannot be placed. */
  imageMetresWide: string | null;
  imageOpacity: number;
  drawingCount: number;
}

export type StockFloorInput = Pick<
  StockFloorRow,
  'establishmentId' | 'name' | 'level' | 'imageFileId' | 'imageMetresWide' | 'imageOpacity'
>;

/**
 * One rectangle on a floor and the location it is drawn for. The measurements are decimal strings as the API holds
 * them, in METRES: a plan is rescanned and recropped over a building's life while the building does not move.
 */
export interface StockDrawingRow {
  id: string;
  floorId: string;
  locationId: string;
  /** What the screen writes on the rectangle; the rectangle itself knows none of it. */
  locationCode: string;
  locationName: string;
  locationKind: StockLocationKind;
  x: string;
  y: string;
  width: string;
  /** The second side of the footprint, not a height. */
  depth: string;
  /** Whole degrees clockwise about the rectangle's own centre. */
  rotation: number;
  /** How tall it stands, for the 3D view; zero for a bay marked out on the floor. */
  height: string;
}

export type StockDrawingInput = Pick<
  StockDrawingRow,
  'locationId' | 'x' | 'y' | 'width' | 'depth' | 'rotation' | 'height'
>;

/**
 * What the stock forms offer: the company's establishments. The products are asked for a few at a time through the
 * picker (docs/SPEC.md § 7, 2026-09-17, ruling 3) — holding them here meant the API walked the settings chain once
 * per product in the company before a screen had drawn anything.
 */
export interface StockOptions {
  establishments: StockEstablishmentOption[];
}
