// SPDX-License-Identifier: AGPL-3.0-or-later

/** Why the API refused, as the stock screens translate it. */
export type InventoryError =
  'network' | 'not_found' | 'code_taken' | 'in_use' | 'invalid' | 'level_taken' | 'cost_known';

/** Where stock is kept, from the whole site down to one bin (docs/SPEC.md § 7, 2026-09-14). */
export type StockLocationKind =
  'site' | 'building' | 'floor' | 'zone' | 'rack' | 'bin' | 'quarantine';
export const STOCK_LOCATION_KINDS: readonly StockLocationKind[] = [
  'site',
  'building',
  'floor',
  'zone',
  'rack',
  'bin',
  'quarantine',
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

/** `cost_correction`: the share of a late receipt cost booked against the sales since (no quantity, a value). */
export type StockSourceType =
  | 'receipt'
  | 'count'
  | 'move'
  | 'loss'
  | 'delivery_note'
  | 'invoice'
  | 'credit_note'
  | 'cost_correction';
export const STOCK_SOURCE_TYPES: readonly StockSourceType[] = [
  'receipt',
  'count',
  'move',
  'loss',
  'delivery_note',
  'invoice',
  'credit_note',
  'cost_correction',
];

/** What a person records: goods received, what a count found on the shelf, goods moved, or goods written off. */
export type StockOperation = 'receive' | 'count' | 'move' | 'loss';

/** Why goods were written off: the API's list, in the order the form offers them. */
export const STOCK_LOSS_REASONS = [
  'lost',
  'broken',
  'expired',
  'stolen',
  'internal_use',
  'sample',
] as const;
export type StockLossReason = (typeof STOCK_LOSS_REASONS)[number];

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
  /** Any of these, each filter's values OR'd and the filters AND'd (row 197); a location with every one under it. */
  locationIds: readonly string[];
  establishmentIds: readonly string[];
  productIds: readonly string[];
  /** What more left than came in, a quantity below zero, or the rest; null asks either. */
  negative: 'yes' | 'no' | null;
  /** A lot past its use-by day and not released, or the rest; null asks either. */
  expired: 'yes' | 'no' | null;
  /** The ends of the use-by interval, `lotExpiresOn.from` and `lotExpiresOn.to`: each already valid. */
  intervals: Readonly<Record<string, string>>;
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
  /** Any of these, each filter's values OR'd and the filters AND'd (row 197). */
  productIds: readonly string[];
  /** Each with every location under it, which the API adds. */
  locationIds: readonly string[];
  kinds: readonly StockMovementKind[];
  sourceTypes: readonly StockSourceType[];
  reasons: readonly StockLossReason[];
  /** A receipt whose cost waits to be entered, or every other movement; null asks either. */
  costToComplete: 'yes' | 'no' | null;
  /** The ends of the day interval, `movedAt.from` and `movedAt.to`, in the company's own calendar: each already valid. */
  intervals: Readonly<Record<string, string>>;
  /** A lot or serial code, matched whole whatever its case: where a recall starts (row 63 slice 10). */
  lot: string | null;
  order: { key: StockMovementSortKey; direction: 'asc' | 'desc' } | null;
}

/** What is on hand of one product at one location: the sum of its movements, which may fall below zero. */
/** A good whose home is a place or a place under it, as the stock map reads it from the place. */
export interface LocationHomeRow {
  productId: string;
  productReference: string;
  productName: string;
  /** The place the home is: the one asked about, or a bin under it. */
  locationId: string;
  locationCode: string;
  main: boolean;
}

/**
 * What a place on the map holds — its stock and that of every place under it, one page of it — and what has its home
 * there, so a home holding nothing reads as empty.
 */
export interface LocationContents {
  locationId: string;
  /** The words the place was searched with, empty for all of it: what is shown is only what they matched. */
  q: string;
  levels: readonly StockLevelRow[];
  /** How many rows the stock has in all; more than `levels` when it outgrew one page. */
  total: number;
  homes: readonly LocationHomeRow[];
}

/** One place's share of a product found on the map, its lots together. */
export interface WhereaboutLine {
  locationId: string;
  locationCode: string;
  locationName: string;
  quantity: string;
}

/**
 * Where a product is, as the map lights it: a drawn place holding some, on its floor, with what each place at or
 * under it holds; or, with no floor and no place, what lies where nothing is drawn.
 */
export interface WhereaboutRow {
  floorId: string | null;
  locationId: string | null;
  locationCode: string | null;
  locationName: string | null;
  quantity: string;
  lines: readonly WhereaboutLine[];
}

/** A product found on the map: what it is, how its stock is counted, and where it is. */
export interface Whereabouts {
  productId: string;
  productReference: string;
  productName: string;
  unitName: string;
  unitDecimals: number;
  rows: readonly WhereaboutRow[];
}

/**
 * What the map was asked to find, in the order asked — one product for a search, a delivery note's lines together —
 * and each of them, found or not: a product found nowhere is said, never left out.
 */
export interface MapSearch {
  productIds: readonly string[];
  products: readonly Whereabouts[];
}

export interface StockLevelRow {
  /** The product and the location together: a row is the pair, and neither alone names it. */
  id: string;
  productId: string;
  productReference: string;
  productName: string;
  unitCode: string;
  /** The unit as the screens name it; the code is the settings' word. */
  unitName: string;
  /** How many decimals that unit counts in, so a row is shown as its unit counts without the whole catalogue. */
  unitDecimals: number;
  locationId: string;
  locationCode: string;
  locationName: string;
  establishmentId: string;
  /** A signed decimal string at three decimals, "-2.000". */
  quantity: string;
  /** The lot this quantity is of, for a product tracked by lot or serial; null for an untracked one. */
  lotId: string | null;
  lotCode: string | null;
  /** The day the lot is used by (ISO), when it has one. */
  lotExpiresOn: string | null;
  /** Whether a person let the lot leave although it is past its date. */
  lotReleased: boolean;
  /** The product's main photo, null while it has none. */
  mainPhotoId: string | null;
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
  /** The lot or serial number it moved, for a tracked product; null for an untracked one. */
  lotCode: string | null;
  /** Why the goods were written off; null on every movement but a loss. */
  reason: StockLossReason | null;
  note: string | null;
  recordedBy: string | null;
  /** The document a receipt came with (docs/SPEC.md row 190): who supplied it, their own reference, the day it arrived. */
  vendorId: string | null;
  vendorName: string | null;
  supplierReference: string | null;
  receivedOn: string | null;
  at: string;
  /** Whether somebody typed what one unit cost, rather than the average a receipt without one is valued at. */
  costTyped: boolean;
  /**
   * A receipt recorded by someone who could not read costs, whose cost a cost reader is asked to enter (docs/SPEC.md
   * § 7, audit 2026-10-06 C challenge 9).
   */
  costToComplete: boolean;
}

/** What the stock of one product is worth, at the weighted average of what came in. */
export interface StockValuationLine {
  productId: string;
  productReference: string;
  productName: string;
  unitCode: string;
  /** The unit as the screens name it; the code is the settings' word. */
  unitName: string;
  quantity: string;
  /** The average cost of one unit, four decimals; null when no stock of it has a cost. */
  unitCost: string | null;
  value: string;
  /** The part of the quantity with no known cost, left out of the value. */
  unvaluedQuantity: string;
  /** The part with no recorded cost, valued at the product's cost price now: the value is an estimate. */
  estimatedQuantity: string;
}

export interface StockValuation {
  /** The total of every line's value. */
  total: string;
  /** Whether the total holds an estimated part. */
  estimated: boolean;
  lines: StockValuationLine[];
}

export interface StockMovementInput {
  operation: StockOperation;
  productId: string;
  /** Where the goods are; for a move, where they leave from. */
  locationId: string;
  /** Where a move puts them; absent for anything else. */
  toLocationId?: string;
  quantity: string;
  /** The lot or serial number of a tracked product; a receipt or a count opens it the first time it is met. */
  lotCode?: string;
  /** The day the lot is used by (ISO), on a receipt or a count only: a move takes the lot's own. */
  lotExpiresOn?: string;
  /** What one unit cost, on a receipt only; left out, the average of what the stock already is. */
  unitCost?: string;
  /** What a receipt does to the product's cost, where the company lets the person choose; never without a cost typed. */
  applyCost?: CostBasis;
  /** The document a receipt came with, all optional; a receipt only. */
  vendorId?: string;
  supplierReference?: string;
  receivedOn?: string;
  /** Why the goods were written off, and what was said about it; on a loss only. */
  reason?: StockLossReason;
  note?: string;
}

/**
 * One delivery shared out over several places (docs/SPEC.md § 7, 2026-10-04 09:04): a receipt per place, stored whole
 * or refused whole, so what the shelves hold is the sum of the movements. The parts are in the order the person gave
 * them and each place appears once; the lot and the cost apply to every part.
 */
export interface StockReceiptInput {
  productId: string;
  parts: readonly { locationId: string; quantity: string }[];
  lotCode?: string;
  lotExpiresOn?: string;
  unitCost?: string;
  applyCost?: CostBasis;
  vendorId?: string;
  supplierReference?: string;
  receivedOn?: string;
}

/** What was found at several places, as an opening count is taken: each part what was found there, 0 included. */
export interface StockCountInput {
  productId: string;
  parts: readonly { locationId: string; quantity: string }[];
  lotCode?: string;
  lotExpiresOn?: string;
}

/** What a receipt does to a product's cost: the company decides, or leaves the choice to the person. */
export type CostOnReceive = 'suggest' | 'average' | 'last' | 'manual';

/** The two figures a receipt can move the cost to: the average it leaves, or the cost typed on it. */
export type CostBasis = 'average' | 'last';
export const COST_BASES: readonly CostBasis[] = ['average', 'last'];

/** What a receipt of a product would do to its cost, as the form shows it before anything is saved. */
export interface ReceiptCostView {
  mode: CostOnReceive;
  costNow: string | null;
  /** The weighted average as this receipt would leave it. */
  average: string | null;
  /** The latest cost somebody typed on a receipt, and when. */
  lastCost: string | null;
  lastAt: string | null;
}

/** One vendor as the receive form's picker answers it. */
export interface StockVendorOption {
  id: string;
  number: string;
  name: string;
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
  /** How its stock is told apart, so a movement asks for the lot of a tracked product (2026-09-23 slice 7). */
  tracking: 'none' | 'lot' | 'serial';
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
  /**
   * The floor's own size in metres, which the board frames and outlines (docs/SPEC.md § 7, 2026-09-22). Null on a
   * floor drawn before it was asked; the board then frames what is drawn on it.
   */
  widthMetres: string | null;
  depthMetres: string | null;
  imageFileId: string | null;
  /** What the whole image spans on the ground, in metres; without it the image cannot be placed. */
  imageMetresWide: string | null;
  imageOpacity: number;
  drawingCount: number;
}

export type StockFloorInput = Pick<
  StockFloorRow,
  'establishmentId' | 'name' | 'level' | 'imageFileId' | 'imageMetresWide' | 'imageOpacity'
> & {
  /** Asked on every write: a floor is saved with its size. */
  widthMetres: string;
  depthMetres: string;
};

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
> & {
  /** A place created with the rectangle, all three or none, and then no `locationId`: the API refuses one naming both. */
  newLocationKind?: StockLocationKind;
  newLocationCode?: string;
  newLocationName?: string;
};

/**
 * The structure layer's four tools, which are the four things the building is drawn out of. The order is the
 * approved canvas's own: mur, porte, poteau, quai.
 */
export const STRUCTURE_KINDS = ['wall', 'door', 'post', 'dock'] as const;

export type StructureKind = (typeof STRUCTURE_KINDS)[number];

/**
 * One piece of the building on a floor. It names no location and never will: nothing here holds goods, which is
 * exactly why it is a layer of its own rather than a stock location that would sit in every list forever.
 */
export interface StockStructureRow {
  id: string;
  floorId: string;
  kind: StructureKind;
  /**
   * What the store already calls this piece — "Porte du quai 2" — and the empty string where it calls it nothing,
   * which most walls are (docs/SPEC.md § 7, 2026-09-22).
   */
  name: string;
  x: string;
  y: string;
  /** How long it runs: a wall's length, a door's opening, a post's side. */
  width: string;
  /** How thick it is on the floor — the second side of the footprint, not a height. */
  depth: string;
  rotation: number;
  height: string;
}

export type StockStructureInput = Pick<
  StockStructureRow,
  'kind' | 'name' | 'x' | 'y' | 'width' | 'depth' | 'rotation' | 'height'
>;

/**
 * A repeat of one rectangle down an aisle: how many MORE of it, the free floor between two of them in metres, which
 * way across the FLOOR, and what the first copy is called — the rest count on from its number.
 */
export interface StockRepeatInput {
  count: number;
  spacing: string;
  way: 'up' | 'down' | 'left' | 'right';
  firstCode: string;
}

/**
 * What the stock forms offer: the company's establishments. The products are asked for a few at a time through the
 * picker (docs/SPEC.md § 7, 2026-09-17, ruling 3) — holding them here meant the API walked the settings chain once
 * per product in the company before a screen had drawn anything.
 */
export interface StockOptions {
  establishments: StockEstablishmentOption[];
  /** The plan palette's ready-made shapes, at the sizes this company set for them. */
  planShapes: StockPlanShape[];
  /** The structure layer's four tools, at the measurements this company builds at. */
  structureShapes: StockStructureShape[];
}

/**
 * One tool of the structure layer. It carries a height where a palette shape does not: a wall's height is the same
 * for the whole building until the company says otherwise, while a rack's is a fact about that rack somebody went
 * and measured.
 */
export interface StockStructureShape {
  kind: StructureKind;
  width: number;
  depth: number;
  height: number;
}

/**
 * One shape of the plan's palette. Its sizes are the company's settings and never constants here (the approved
 * canvas: a warehouse of pallets and a shop do not have the same racks), so they arrive with the stock options.
 */
export interface StockPlanShape {
  /** `rack`, `zone`, `aisle` or `dock` — which also names it on screen and gives it its colour. */
  shape: string;
  width: number;
  depth: number;
}

/** What an establishment's shelves hold of a product whose stock is kept, counted in the unit `unitId` names. */
export interface StockOnHand {
  productId: string;
  unitId: string;
  /** A decimal string, below zero when more left than came in. */
  onHand: string;
}
