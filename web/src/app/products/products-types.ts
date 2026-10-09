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
  /** Any of these, each filter's values OR'd and the filters AND'd (row 197). */
  kinds: readonly ProductKind[];
  trackings: readonly ProductTracking[];
  /** Each with every category under it, which the API adds. */
  categoryIds: readonly string[];
  isActive: boolean | null;
  /** The ends of the price interval, `unitPriceNet.min` and `unitPriceNet.max`, each already a valid decimal. */
  intervals: Readonly<Record<string, string>>;
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
  /** The name it shares with the products that can stand in for it; null when it has none. */
  substitutionGroup: string | null;
  /** The photo shown wherever it is picked or seen (`ProductPhotosApi.url`); null when it has none. */
  mainPhotoId: string | null;
}

/** What moved a product's cost price: its creation, a person's edit, or a stock receipt that applied one. */
export type ProductCostSource = 'created' | 'edited' | 'receipt';

/** One change of what a product costs the company, as the history lists it, the newest first. */
export interface ProductCostChangeRow {
  id: string;
  /** The cost before and after, four decimals; null when the product had none, or lost it. */
  oldCost: string | null;
  newCost: string | null;
  source: ProductCostSource;
  /** An instant, ISO. */
  at: string;
}

/** A product that can stand in for another, with what is on hand of it when the person may read stock. */
export interface ProductSubstituteRow {
  id: string;
  reference: string;
  name: string;
  isActive: boolean;
  unitPriceNet: string;
  /** Wherever it is, "12.000"; null when stock cannot be read here. */
  onHand: string | null;
}

/** The longest a substitution group's name may be, as the API keeps it. */
export const SUBSTITUTION_GROUP_MAX = 80;

/** Not at all, by lot, or one piece at a time by its serial number (docs/SPEC.md § 7, 2026-09-22 11:10). */
export type ProductTracking = 'none' | 'lot' | 'serial';
export const PRODUCT_TRACKINGS: readonly ProductTracking[] = ['none', 'lot', 'serial'];

/** A tracking the API named; anything else reads as none, which asks for no lot. */
export function trackingOf(value: string): ProductTracking {
  return PRODUCT_TRACKINGS.find((each) => each === value) ?? 'none';
}

/** A lot or serial as a label carries it: printable characters without space or accent, 40 at most, as the API keeps it. */
export const LOT_CODE_PATTERN = /^[\x21-\x7E]{1,40}$/;

/** What a save sends: the main photo is the gallery's to say, never the form's. */
export type ProductInput = Omit<ProductRow, 'id' | 'barcodes' | 'mainPhotoId'>;

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
  /** How many photos a product may have, and how large one may be in bytes: the API's parameters. */
  photosPerProduct: number;
  photoMaxBytes: number;
}

/**
 * Where a product normally lives, one entry per establishment that has one (docs/SPEC.md row 101). It is what a
 * receipt proposes, never a rule: stock may still be put anywhere.
 */
/**
 * A product's reorder point in one establishment (docs/SPEC.md § 7, 2026-09-24 11:40): every establishment of the
 * company is listed, `quantity` null where the product has none, which means no alert there.
 */
export interface ProductReorderPointRow {
  establishmentId: string;
  establishmentCode: string;
  establishmentName: string;
  /** A decimal string with three decimals, in the product's unit; null for none. */
  quantity: string | null;
}

export interface ProductHomeRow {
  id: string;
  establishmentId: string;
  establishmentCode: string;
  establishmentName: string;
  locationId: string;
  locationCode: string;
  locationName: string;
  /** Where it stands in its establishment's order, from 0. */
  position: number;
  /** The first of its establishment: the place a receipt proposes. */
  main: boolean;
}

/**
 * A price counted with its line taxes on one line of `quantity` (a unit, a pack) by the API's calculator, the one
 * every document uses: before tax, the taxes and what a customer pays, each at the currency's scale.
 */
export interface PricePreviewLine {
  quantity: string;
  net: string;
  tax: string;
  total: string;
}
