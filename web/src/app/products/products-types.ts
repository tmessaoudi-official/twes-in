// SPDX-License-Identifier: AGPL-3.0-or-later

import type { CustomFieldValue } from '../shared/custom-fields/custom-fields-types';

/** Why the API refused, as the products screens translate it. */
export type ProductsError =
  'network' | 'not_found' | 'reference_taken' | 'name_taken' | 'in_use' | 'invalid';

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
  /** Words found in the reference, name or barcode; empty finds all. */
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
  barcode: string | null;
  defaultTaxComponentIds: string[];
  isActive: boolean;
  /** Values by the company's custom field keys for products; a retired field's value stays here. */
  customFields: Record<string, CustomFieldValue>;
}

export type ProductInput = Omit<ProductRow, 'id'>;

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
