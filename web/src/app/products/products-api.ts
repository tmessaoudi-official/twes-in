// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  ApiCompaniesCompanyIdproductsGetCollectionResponse,
  ProductCategoryProductCategoryRead,
  ProductJsonldProductRead,
  ProductCategoryProductCategoryWriteRecordReviseValidationProductCategoryWriteRecordRevise as ProductCategoryRevise,
  ProductCategoryProductCategoryWriteValidationProductCategoryWrite as ProductCategoryProductCategoryWrite,
  ProductHomeProductHomeOrderRecordReviseValidationProductHomeOrderRecordRevise,
  ProductHomeProductHomeRead,
  ProductHomeProductHomeWrittenValidationProductHomeOrderRecordRevise,
  ProductReorderPointProductReorderPointRead,
  ProductReorderPointProductReorderPointReadProductReorderPointWrittenValidationProductReorderPointWriteRecordRevise,
  ProductReorderPointProductReorderPointWriteRecordReviseValidationProductReorderPointWriteRecordRevise,
  ProductOptionsProductOptionsRead,
  PricePreviewPricePreviewReadValidationPricePreviewWrite as PricePreviewRead,
  PricePreviewPricePreviewWriteValidationPricePreviewWrite as PricePreviewWrite,
  ProductBarcodeRowJsonldProductRead,
  ProductBarcodeRowProductBarcodesRead,
  ProductBarcodeRowProductRead,
  ProductBarcodesProductBarcodesReadValidationProductBarcodesWriteRecordRevise as ProductBarcodesProductBarcodesRead,
  ProductBarcodesProductBarcodesWriteRecordReviseValidationProductBarcodesWriteRecordRevise as ProductBarcodesProductBarcodesWrite,
  ProductProductRead,
  ProductReferencePreviewProductReferencePreviewRead,
  ProductProductWriteRecordReviseValidationProductWriteRecordRevise as ProductProductRevise,
  ProductProductWriteValidationProductWrite as ProductProductWrite,
  ProductScanProductScanRead,
  ProductCostChangeProductCostChangeRead,
  ProductSubstituteProductSubstituteRead,
  ProductSubstitutionGroupProductSubstitutionGroupRead,
  StockTotalStockTotalRead,
  ProductLabelProductLabelRead,
} from '../api/types.gen';
import { isRecordChanged } from '../shared/errors/record-changed';
import { type ExportFormat, exportAddress } from '../shared/list/export-address';
import { type FieldRefusal, refusalsIn } from '../shared/form/field-refusals';
import { apiRangeKey } from '../shared/list/list-filters';
import type { ListPage } from '../shared/list/list-types';
import {
  BARCODE_ROLES,
  PRODUCT_KINDS,
  PRODUCT_TRACKINGS,
  type BarcodesRefusal,
  type LabelledProduct,
  type LineTaxFamily,
  type ProductBarcode,
  type ProductCategoryInput,
  type ProductCategoryRow,
  type ProductHomeRow,
  type ProductReorderPointRow,
  type ProductCostChangeRow,
  type ProductSubstituteRow,
  type ProductInput,
  type PricePreviewLine,
  type ProductOptions,
  type ProductRow,
  type ProductScan,
  type ProductSearch,
  type ProductsError,
} from './products-types';

/** Thrown when the API refuses; carries the code the UI translates. */
export class ProductsRefused extends Error {
  constructor(
    readonly code: ProductsError,
    /** Each field a product's save was refused on, when the API named them. */
    readonly refusals: readonly FieldRefusal[] = [],
  ) {
    super(code);
  }
}

/** A list of codes refused, naming the row at fault so the screen can put the message under it. */
export class BarcodesRefused extends ProductsRefused {
  constructor(readonly refusal: BarcodesRefusal) {
    super(refusal.code);
  }
}

const LINE_TAX_FAMILIES: readonly LineTaxFamily[] = ['vat', 'levy'];

/** The HTTP edge of the products feature: the only code here that knows endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class ProductsApi {
  private readonly http = inject(HttpClient);

  /** What the product form offers: the company's currency, active units and active line taxes. */
  async options(companyId: string): Promise<ProductOptions> {
    return this.guard(async () =>
      toOptions(
        await firstValueFrom(
          this.http.get<ProductOptionsProductOptionsRead>(path(companyId, 'product-options')),
        ),
      ),
    );
  }

  /** One page of the products the search finds, as Hydra carries it: the rows and the total. */
  async products(companyId: string, search: ProductSearch): Promise<ListPage<ProductRow>> {
    return this.guard(async () => {
      const page = await firstValueFrom(
        this.http.get<ApiCompaniesCompanyIdproductsGetCollectionResponse>(
          path(companyId, 'products'),
          {
            headers: { Accept: 'application/ld+json' },
            params: toSearchParams(search),
          },
        ),
      );
      if (page.totalItems === undefined)
        throw new Error('A page of products came without its total.');
      return { rows: page.member.map(toProduct), total: page.totalItems };
    });
  }

  /** Where the products the search finds are downloaded as a file, every page of them. */
  exportUrl(companyId: string, search: ProductSearch, format: ExportFormat): string {
    return exportAddress(companyId, 'products', toSearchParams(search), format);
  }

  async product(companyId: string, id: string): Promise<ProductRow> {
    return this.guard(async () =>
      toProduct(
        await firstValueFrom(this.http.get<ProductProductRead>(path(companyId, 'products', id))),
      ),
    );
  }

  /** The labels of the products asked for, in the order asked, each once; a product the company does not hold is left out. */
  async labels(companyId: string, productIds: readonly string[]): Promise<LabelledProduct[]> {
    let params = new HttpParams();
    for (const id of productIds) params = params.append('productId[]', id);
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<ProductLabelProductLabelRead[]>(path(companyId, 'product-labels'), {
            params,
          }),
        )
      ).map((label) => ({
        productId: label.productId,
        reference: label.reference,
        name: label.name,
        codes: label.codes.map((code) => ({ ...code })),
      })),
    );
  }

  /**
   * What `unitPriceNet` comes to with the line taxes `taxComponentIds`, one line per quantity, counted by the API: the
   * price calculator's with-tax figures. Nothing is saved.
   */
  async pricePreview(
    companyId: string,
    unitPriceNet: string,
    taxComponentIds: readonly string[],
    quantities: readonly string[],
  ): Promise<PricePreviewLine[]> {
    const body: PricePreviewWrite = {
      unitPriceNet,
      taxComponentIds: [...taxComponentIds],
      quantities: [...quantities],
    };
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.post<PricePreviewRead>(path(companyId, 'price-preview'), body),
        )
      ).prices.map(({ quantity, net, tax, total }) => ({ quantity, net, tax, total })),
    );
  }

  /**
   * The reference a new product left without one would be given if it were saved now, filed in that category: what
   * the form shows in the field. Nothing is taken; the save takes the next free one.
   */
  async referencePreview(companyId: string, categoryId: string | null): Promise<string> {
    return this.guard(
      async () =>
        (
          await firstValueFrom(
            this.http.get<ProductReferencePreviewProductReferencePreviewRead>(
              path(companyId, 'product-reference-preview'),
              { params: categoryId === null ? {} : { categoryId } },
            ),
          )
        ).reference,
    );
  }

  /**
   * 409 when another product of the company has the reference; 422 naming the field the API refused. A reference
   * left empty is given the next free one of the company's format.
   */
  async createProduct(companyId: string, input: ProductInput): Promise<ProductRow> {
    return this.saving(async () =>
      toProduct(
        await firstValueFrom(
          this.http.post<ProductProductRead>(path(companyId, 'products'), toProductBody(input)),
        ),
      ),
    );
  }

  /** `version` is the one the form was opened at: a later one saved elsewhere answers `record_changed`. */
  async reviseProduct(
    companyId: string,
    id: string,
    input: ProductInput,
    version: number | null,
  ): Promise<ProductRow> {
    const body: ProductProductRevise = { ...toProductBody(input), version };
    return this.saving(async () =>
      toProduct(
        await firstValueFrom(
          this.http.put<ProductProductRead>(path(companyId, 'products', id), body),
        ),
      ),
    );
  }

  /**
   * The product's codes become exactly these (docs/SPEC.md § 7, 2026-09-22 11:05). A code another product holds
   * answers 409 naming the row and that product; a malformed row answers 422 naming it. Both reach the caller as a
   * `BarcodesRefused` carrying the row. `version` is the product's, the one the list was read at: the codes are shown
   * beside the product form, so one saved since answers `record_changed`, and the answer carries the version they left.
   */
  async replaceBarcodes(
    companyId: string,
    productId: string,
    barcodes: readonly ProductBarcode[],
    version: number,
  ): Promise<{ barcodes: ProductBarcode[]; version: number }> {
    const body: ProductBarcodesProductBarcodesWrite = {
      barcodes: barcodes.map((row) => ({ ...row })),
      version,
    };
    try {
      const saved = await firstValueFrom(
        this.http.put<ProductBarcodesProductBarcodesRead>(
          `${path(companyId, 'products', productId)}/barcodes`,
          body,
        ),
      );
      // Every answer carries it; a 0 would be refused by the API rather than taken for a version.
      return { barcodes: (saved.barcodes ?? []).map(toBarcode), version: saved.version ?? 0 };
    } catch (error) {
      throw new BarcodesRefused(barcodesRefusal(error));
    }
  }

  /** What one scan names, sent as it came; null when no product of the company answers to it. */
  async scan(companyId: string, code: string): Promise<ProductScan | null> {
    try {
      const raw = await firstValueFrom(
        this.http.get<ProductScanProductScanRead>(path(companyId, 'product-scan'), {
          params: new HttpParams().set('code', code),
        }),
      );
      return {
        productId: raw.productId,
        reference: raw.reference,
        name: raw.name,
        isActive: raw.isActive,
        code: raw.code,
        role: raw.role,
        quantity: raw.quantity,
        lot: raw.lot ?? null,
        useBy: raw.useBy ?? null,
        serial: raw.serial ?? null,
        unitPriceNet: raw.unitPriceNet,
        unitPriceGross: raw.unitPriceGross,
        priceGross: raw.priceGross,
        mainPhotoId: raw.mainPhotoId ?? null,
      };
    } catch (error) {
      if (error instanceof HttpErrorResponse && error.status === 404) return null;
      throw new ProductsRefused(codeOf(error, 'invalid'));
    }
  }

  async categories(companyId: string): Promise<ProductCategoryRow[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<ProductCategoryProductCategoryRead[]>(
            path(companyId, 'product-categories'),
          ),
        )
      ).map(toCategory),
    );
  }

  async createCategory(
    companyId: string,
    input: ProductCategoryInput,
  ): Promise<ProductCategoryRow> {
    const body: ProductCategoryProductCategoryWrite = { ...input };
    return this.guard(
      async () =>
        toCategory(
          await firstValueFrom(
            this.http.post<ProductCategoryProductCategoryRead>(
              path(companyId, 'product-categories'),
              body,
            ),
          ),
        ),
      'name_taken',
    );
  }

  /**
   * 422 when the new parent is the category itself or one of its subcategories. `version` is the one the form was
   * opened at: a later one saved elsewhere answers `record_changed`.
   */
  async reviseCategory(
    companyId: string,
    id: string,
    input: ProductCategoryInput,
    version: number | null,
  ): Promise<ProductCategoryRow> {
    const body: ProductCategoryRevise = { ...input, version };
    return this.guard(
      async () =>
        toCategory(
          await firstValueFrom(
            this.http.put<ProductCategoryProductCategoryRead>(
              path(companyId, 'product-categories', id),
              body,
            ),
          ),
        ),
      'name_taken',
    );
  }

  /** 409 while the category still holds a subcategory or a product. */
  async deleteCategory(companyId: string, id: string): Promise<void> {
    await this.guard(
      async () => firstValueFrom(this.http.delete(path(companyId, 'product-categories', id))),
      'in_use',
    );
  }

  /** Where this product normally lives, one entry per establishment that has one (docs/SPEC.md row 101). */
  async homes(companyId: string, productId: string): Promise<ProductHomeRow[]> {
    return this.guard(async () => {
      const rows = await firstValueFrom(
        this.http.get<ProductHomeProductHomeRead[]>(homesPath(companyId, productId)),
      );
      return rows.map(toHome);
    });
  }

  /**
   * One establishment's homes written as a whole list, in order, the first the main one a receipt proposes: the places
   * named are its homes and any it had that are not named stop being homes; an empty list clears them. It answers the
   * product's version the write left; what the screen shows is read again. `version` is the product's, the one the
   * list was read at: a product saved since answers `record_changed`.
   */
  async replaceHomes(
    companyId: string,
    productId: string,
    establishmentId: string,
    locationIds: readonly string[],
    version: number,
  ): Promise<number> {
    const body: ProductHomeProductHomeOrderRecordReviseValidationProductHomeOrderRecordRevise = {
      locationIds: [...locationIds],
      version,
    };
    return this.guard(async () => {
      const written = await firstValueFrom(
        this.http.put<ProductHomeProductHomeWrittenValidationProductHomeOrderRecordRevise>(
          `${homesPath(companyId, productId)}/${encodeURIComponent(establishmentId)}`,
          body,
        ),
      );
      // Every answer carries it; a 0 would be refused by the API rather than taken for a version.
      return written.productVersion ?? 0;
    });
  }

  /** Every establishment of the company with this product's reorder point there, null where it has none. */
  async reorderPoints(companyId: string, productId: string): Promise<ProductReorderPointRow[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<ProductReorderPointProductReorderPointRead[]>(
            reorderPointsPath(companyId, productId),
          ),
        )
      ).map(toReorderPoint),
    );
  }

  /**
   * Sets the product's reorder point in that establishment, changing the one it had there, or with no quantity takes
   * it away (no alert there; one it never had is not an error). It answers the product's version the write left.
   * `version` is the product's, the one the points were read at: a product saved since answers `record_changed`.
   */
  async setReorderPoint(
    companyId: string,
    productId: string,
    establishmentId: string,
    quantity: string | null,
    version: number,
  ): Promise<number> {
    const body: ProductReorderPointProductReorderPointWriteRecordReviseValidationProductReorderPointWriteRecordRevise =
      { quantity, version };
    return this.guard(async () => {
      const written = await firstValueFrom(
        this.http.put<ProductReorderPointProductReorderPointReadProductReorderPointWrittenValidationProductReorderPointWriteRecordRevise>(
          `${reorderPointsPath(companyId, productId)}/${encodeURIComponent(establishmentId)}`,
          body,
        ),
      );
      // Every answer carries it; a 0 would be refused by the API rather than taken for a version.
      return written.productVersion ?? 0;
    });
  }

  /** What the product has cost the company over time, the latest changes first; a 404 for whoever may not read costs. */
  async costHistory(companyId: string, productId: string): Promise<ProductCostChangeRow[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<ProductCostChangeProductCostChangeRead[]>(
            `${path(companyId, 'products', productId)}/cost-history`,
          ),
        )
      ).map((raw) => ({
        id: raw.id ?? '',
        oldCost: raw.oldCost ?? null,
        newCost: raw.newCost ?? null,
        source: raw.source ?? 'edited',
        at: raw.at ?? '',
      })),
    );
  }

  /** The names of the substitution groups the company's products carry, by name. */
  async substitutionGroups(companyId: string): Promise<string[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<ProductSubstitutionGroupProductSubstitutionGroupRead[]>(
            path(companyId, 'product-substitution-groups'),
          ),
        )
      ).map((raw) => raw.name ?? ''),
    );
  }

  /** The other active products of the product's substitution group, by reference; empty when it has no group. */
  async substitutes(companyId: string, productId: string): Promise<ProductSubstituteRow[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<ProductSubstituteProductSubstituteRead[]>(
            `${path(companyId, 'products', productId)}/substitutes`,
          ),
        )
      ).map((raw) => ({
        id: raw.id ?? '',
        reference: raw.reference ?? '',
        name: raw.name ?? '',
        isActive: raw.isActive ?? true,
        unitPriceNet: raw.unitPriceNet ?? '',
        onHand: null,
      })),
    );
  }

  /** What is on hand of each product asked for, by its id, every location together. */
  async stockTotals(
    companyId: string,
    productIds: readonly string[],
  ): Promise<Map<string, string>> {
    if (productIds.length === 0) return new Map();
    let params = new HttpParams();
    for (const id of productIds) params = params.append('productId[]', id);
    return this.guard(async () => {
      const rows = await firstValueFrom(
        this.http.get<StockTotalStockTotalRead[]>(path(companyId, 'stock-totals'), {
          params,
        }),
      );
      return new Map(rows.map((row) => [row.productId ?? '', row.quantity ?? '0.000']));
    });
  }

  /** A 409 means what the endpoint makes it mean: a reference or a name another row has, or a category in use. */
  private async guard<T>(
    call: () => Promise<T>,
    conflict: ProductsError = 'reference_taken',
  ): Promise<T> {
    try {
      return await call();
    } catch (error) {
      throw new ProductsRefused(codeOf(error, conflict));
    }
  }

  /** A product's save, the only call whose refusals name the fields of the form. */
  private async saving<T>(call: () => Promise<T>): Promise<T> {
    try {
      return await call();
    } catch (error) {
      const refusals =
        error instanceof HttpErrorResponse && error.status === 422 ? refusalsIn(error.error) : [];
      throw new ProductsRefused(codeOf(error, 'reference_taken'), refusals);
    }
  }
}

function codeOf(error: unknown, conflict: ProductsError): ProductsError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return 'network';
  }
  switch (error.status) {
    case 404:
      return 'not_found';
    case 409:
      return isRecordChanged(error.error) ? 'record_changed' : conflict;
    default:
      return 'invalid';
  }
}

const homesPath = (companyId: string, productId: string): string =>
  `${path(companyId, 'products', productId)}/home-locations`;

const reorderPointsPath = (companyId: string, productId: string): string =>
  `${path(companyId, 'products', productId)}/reorder-points`;

function toReorderPoint(raw: ProductReorderPointProductReorderPointRead): ProductReorderPointRow {
  return {
    establishmentId: raw.establishmentId ?? '',
    establishmentCode: raw.establishmentCode ?? '',
    establishmentName: raw.establishmentName ?? '',
    quantity: raw.quantity ?? null,
  };
}

function toHome(raw: ProductHomeProductHomeRead): ProductHomeRow {
  return {
    id: raw.id ?? '',
    establishmentId: raw.establishmentId ?? '',
    establishmentCode: raw.establishmentCode ?? '',
    establishmentName: raw.establishmentName ?? '',
    locationId: raw.locationId ?? '',
    locationCode: raw.locationCode ?? '',
    locationName: raw.locationName ?? '',
    position: raw.position ?? 0,
    main: raw.main ?? false,
  };
}

const path = (companyId: string, collection: string, id?: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}/${collection}${id === undefined ? '' : `/${encodeURIComponent(id)}`}`;

function toSearchParams(search: ProductSearch): HttpParams {
  let params = new HttpParams().set('page', search.page).set('itemsPerPage', search.itemsPerPage);
  if (search.q.trim() !== '') params = params.set('q', search.q.trim());
  for (const kind of search.kinds) params = params.append('kind[]', kind);
  for (const tracking of search.trackings) params = params.append('tracking[]', tracking);
  for (const id of search.categoryIds) params = params.append('categoryId[]', id);
  if (search.isActive !== null) params = params.set('isActive', search.isActive);
  for (const [key, value] of Object.entries(search.intervals)) {
    params = params.set(apiRangeKey(key), value);
  }
  if (search.order !== null)
    params = params.set(`order[${search.order.key}]`, search.order.direction);
  return params;
}

function toProduct(raw: ProductProductRead | ProductJsonldProductRead): ProductRow {
  return {
    id: raw.id ?? '',
    // Every read carries it; a 0 would be refused by the API rather than taken for a version.
    version: raw.version ?? 0,
    reference: raw.reference ?? '',
    name: raw.name ?? '',
    description: raw.description ?? null,
    kind: PRODUCT_KINDS.find((kind) => kind === raw.kind) ?? 'goods',
    unitId: raw.unitId ?? '',
    unitPriceNet: raw.unitPriceNet ?? '',
    costPrice: raw.costPrice ?? null,
    categoryId: raw.categoryId ?? null,
    barcodes: (raw.barcodes ?? []).map(toBarcode),
    defaultTaxComponentIds: (raw.defaultTaxComponentIds ?? []).filter(
      (id): id is string => typeof id === 'string',
    ),
    isActive: raw.isActive ?? true,
    customFields: { ...(raw.customFields ?? {}) },
    tracking: PRODUCT_TRACKINGS.find((tracking) => tracking === raw.tracking) ?? 'none',
    substitutionGroup: raw.substitutionGroup ?? null,
    mainPhotoId: raw.mainPhotoId ?? null,
  };
}

function toProductBody(input: ProductInput): ProductProductWrite {
  return {
    ...input,
    defaultTaxComponentIds: [...input.defaultTaxComponentIds],
    customFields: { ...input.customFields },
  };
}

function toBarcode(
  raw:
    | ProductBarcodeRowProductRead
    | ProductBarcodeRowJsonldProductRead
    | ProductBarcodeRowProductBarcodesRead,
): ProductBarcode {
  return {
    role: BARCODE_ROLES.find((role) => role === raw.role) ?? 'unit',
    code: raw.code ?? '',
    quantity: raw.quantity ?? 1,
    supplierId: raw.supplierId ?? null,
  };
}

/**
 * What the API said about a list of codes. Its refusals name the row the way it writes fields,
 * `barcodes.<index>.<field>: …` (or `barcodes[<index>].<field>` for a shape the validator refused), and a code another
 * product holds says whose: `… is already a code of <reference>.` (ProductBarcodesResource's documented contract).
 */
export function barcodesRefusal(error: unknown): BarcodesRefusal {
  const none: BarcodesRefusal = { code: 'network', index: null, field: null, heldBy: null };
  if (!(error instanceof HttpErrorResponse) || error.status === 0) return none;
  const body: unknown = error.error;
  const text = JSON.stringify(body ?? '');
  const row = /barcodes(?:\.|\[)(\d+)\]?\.(code|role|quantity|supplierId)/.exec(text);
  const index = row ? Number(row[1]) : null;
  const field = row ? row[2] : null;
  switch (error.status) {
    case 404:
      return { ...none, code: 'not_found' };
    case 409: {
      if (isRecordChanged(body)) return { ...none, code: 'record_changed' };
      const detail =
        typeof body === 'object' &&
        body !== null &&
        'detail' in body &&
        typeof body.detail === 'string'
          ? body.detail
          : '';
      const holder = / is already a code of (.+)\.$/.exec(detail);
      return { code: 'barcode_taken', index, field, heldBy: holder ? holder[1] : null };
    }
    default:
      return { code: 'invalid', index, field, heldBy: null };
  }
}

function toOptions(raw: ProductOptionsProductOptionsRead): ProductOptions {
  return {
    currency: raw.currency ?? '',
    currencyScale: raw.currencyScale ?? 2,
    units: (raw.units ?? []).map((unit) => ({
      id: unit.id,
      code: unit.code,
      name: unit.name,
      decimals: unit.decimals,
    })),
    taxes: (raw.taxes ?? []).flatMap((tax) => {
      const family = LINE_TAX_FAMILIES.find((known) => known === tax.family);
      return family === undefined
        ? []
        : [{ id: tax.id, code: tax.code, name: tax.name, family, isDefault: tax.isDefault }];
    }),
    photosPerProduct: raw.photosPerProduct ?? 0,
    photoMaxBytes: raw.photoMaxBytes ?? 0,
  };
}

function toCategory(raw: ProductCategoryProductCategoryRead): ProductCategoryRow {
  return {
    id: raw.id ?? '',
    name: raw.name ?? '',
    parentId: raw.parentId ?? null,
    productCount: raw.productCount ?? 0,
    childCount: raw.childCount ?? 0,
    // Every read carries it; a 0 would be refused by the API rather than taken for a version.
    version: raw.version ?? 0,
  };
}
