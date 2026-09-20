// SPDX-License-Identifier: AGPL-3.0-or-later

/** Why the API refused, as the stock screens translate it. */
export type InventoryError = 'network' | 'not_found' | 'code_taken' | 'in_use' | 'invalid';

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

export type StockMovementKind = 'in' | 'out' | 'adjustment';
export const STOCK_MOVEMENT_KINDS: readonly StockMovementKind[] = ['in', 'out', 'adjustment'];

export type StockSourceType = 'receipt' | 'count' | 'delivery_note';
export const STOCK_SOURCE_TYPES: readonly StockSourceType[] = ['receipt', 'count', 'delivery_note'];

/** What a person records: goods received, or what a count found on the shelf. */
export type StockOperation = 'receive' | 'count';

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
  locationId: string;
  quantity: string;
}

/** One stocked product as the picker answers it. */
export interface StockProductOption {
  id: string;
  reference: string;
  name: string;
  unitCode: string;
  unitDecimals: number;
}

export interface StockEstablishmentOption {
  id: string;
  code: string;
  name: string;
}

/**
 * What the stock forms offer: the company's establishments. The products are asked for a few at a time through the
 * picker (docs/SPEC.md § 7, 2026-09-17, ruling 3) — holding them here meant the API walked the settings chain once
 * per product in the company before a screen had drawn anything.
 */
export interface StockOptions {
  establishments: StockEstablishmentOption[];
}
