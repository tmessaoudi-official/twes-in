// SPDX-License-Identifier: AGPL-3.0-or-later

import type { CustomFieldValue } from '../shared/custom-fields/custom-fields-types';

/** Why the API refused, as the products screens translate it. */
export type ProductsError =
  | 'network'
  | 'not_found'
  | 'reference_taken'
  | 'name_taken'
  | 'in_use'
  | 'invalid'
  | 'barcode_taken'
  /** Stock of the product has moved, so how it is told apart stays as it moved (docs/SPEC.md § 7, 2026-09-23 02:40). */
  | 'tracking_kept';

export type ProductKind = 'goods' | 'service';
export const PRODUCT_KINDS: readonly ProductKind[] = ['goods', 'service'];

/** The families of tax charged on a line; a stamp or a withholding belongs to the document. */
export type LineTaxFamily = 'vat' | 'levy';

/** What the API sorts products by. */
export type ProductSortKey = 'reference' | 'name' | 'kind' | 'category' | 'isActive';

/** One page of the products list as the API searches, narrows and sorts it (docs/SPEC.md § 7, lists at scale). */
export interface ProductSearch {
  /** Numbered from 1. */
  page: number;
  itemsPerPage: number;
  /** Words found in the reference or name, or one of its codes spelled whole; empty finds all. */
  q: string;
  kind: ProductKind | null;
  isActive: boolean | null;
  order: { key: ProductSortKey; direction: 'asc' | 'desc' } | null;
}

export interface ProductRow {
  id: string;
  reference: string;
  name: string;
  description: string | null;
  kind: ProductKind;
  unitId: string;
  /** A decimal string at four decimals, "1250.5000". */
  unitPriceNet: string;
  costPrice: string | null;
  categoryId: string | null;
  /** The codes it answers to, unit first; written on their own (`ProductsApi.replaceBarcodes`). */
  barcodes: ProductBarcode[];
  defaultTaxComponentIds: string[];
  isActive: boolean;
  /** Values by the company's custom field keys for products; a retired field's value stays here. */
  customFields: Record<string, CustomFieldValue>;
  /** How its stock is told apart; the API keeps it once stock has moved (docs/SPEC.md § 7, 2026-09-23 02:40). */
  tracking: ProductTracking;
}

/** Not at all, by lot, or one piece at a time by its serial number (docs/SPEC.md § 7, 2026-09-22 11:10). */
export type ProductTracking = 'none' | 'lot' | 'serial';
export const PRODUCT_TRACKINGS: readonly ProductTracking[] = ['none', 'lot', 'serial'];

/** A tracking the API named; anything else reads as none, which asks for no lot. */
export function trackingOf(value: string): ProductTracking {
  return PRODUCT_TRACKINGS.find((each) => each === value) ?? 'none';
}

/** A lot or serial as a label carries it: printable characters without space or accent, 40 at most, as the API keeps it. */
export const LOT_CODE_PATTERN = /^[\x21-\x7E]{1,40}$/;

export type ProductInput = Omit<ProductRow, 'id' | 'barcodes'>;

/**
 * What a code stands for (docs/SPEC.md § 7, 2026-09-22 11:05): the piece it is sold by, a pack entering several at
 * once, a supplier's own carton, a code the company printed itself.
 */
export type BarcodeRole = 'unit' | 'pack' | 'supplier' | 'internal';
export const BARCODE_ROLES: readonly BarcodeRole[] = ['unit', 'pack', 'supplier', 'internal'];

export interface ProductBarcode {
  role: BarcodeRole;
  /** As printed. */
  code: string;
  /** How many pieces one scan enters: 1 for a unit code, more for a pack. */
  quantity: number;
  /** The supplier who prints it, for a supplier's code only. */
  supplierId: string | null;
}

/** Why a list of codes was refused, and which row of it: the one to put the message under. */
export interface BarcodesRefusal {
  code: ProductsError;
  /** The row at fault, counted from 0 in the list that was sent; null when the API named none. */
  index: number | null;
  /** The field of that row: `code`, `role`, `quantity` or `supplierId`. */
  field: string | null;
  /** For `barcode_taken`, the reference of the product that already answers to the code. */
  heldBy: string | null;
}

/**
 * What one scan names (docs/SPEC.md § 7, 2026-09-23 00:40): the product, the code it holds and its role, how many
 * pieces the scan enters, and for a GS1 scan the lot, use-by date (ISO) and serial it carried.
 */
export interface ProductScan {
  productId: string;
  reference: string;
  name: string;
  isActive: boolean;
  code: string;
  role: BarcodeRole;
  quantity: number;
  lot: string | null;
  useBy: string | null;
  serial: string | null;
  /** What a customer pays for one unit, net; never the cost. */
  unitPriceNet: string;
  /** What a customer pays for one unit, taxes included, as the company's documents count them. */
  unitPriceGross: string;
  /** What a customer pays for what this code enters (`quantity` units), taxes included. */
  priceGross: string;
}

export interface ProductCategoryRow {
  id: string;
  name: string;
  /** Null at the top of the tree. */
  parentId: string | null;
  productCount: number;
  childCount: number;
}

export type ProductCategoryInput = Pick<ProductCategoryRow, 'name' | 'parentId'>;

export interface UnitOption {
  id: string;
  code: string;
  name: string;
  decimals: number;
}

export interface LineTaxOption {
  id: string;
  code: string;
  name: string;
  family: LineTaxFamily;
}

/** What the product form offers: the company's currency, its active units and its active line taxes. */
export interface ProductOptions {
  currency: string;
  currencyScale: number;
  units: UnitOption[];
  taxes: LineTaxOption[];
}

/**
 * Where a product normally lives, one entry per establishment that has one (docs/SPEC.md row 101). It is what a
 * receipt proposes, never a rule: stock may still be put anywhere.
 */
export interface ProductHomeRow {
  id: string;
  establishmentId: string;
  establishmentCode: string;
  establishmentName: string;
  locationId: string;
  locationCode: string;
  locationName: string;
}
