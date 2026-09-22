// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  ApiCompaniesCompanyIdproductsGetCollectionResponse,
  ProductCategoryProductCategoryRead,
  ProductJsonldProductRead,
  ProductCategoryProductCategoryWrite,
  ProductHomeProductHomeRead,
  ProductOptionsProductOptionsRead,
  ProductBarcodeRowJsonldProductRead,
  ProductBarcodeRowProductBarcodesRead,
  ProductBarcodeRowProductRead,
  ProductBarcodesProductBarcodesRead,
  ProductBarcodesProductBarcodesWrite,
  ProductProductRead,
  ProductProductWrite,
  ProductScanProductScanRead,
} from '../api/types.gen';
import type { ListPage } from '../shared/list/list-types';
import {
  BARCODE_ROLES,
  PRODUCT_KINDS,
  type BarcodesRefusal,
  type LineTaxFamily,
  type ProductBarcode,
  type ProductCategoryInput,
  type ProductCategoryRow,
  type ProductHomeRow,
  type ProductInput,
  type ProductOptions,
  type ProductRow,
  type ProductScan,
  type ProductSearch,
  type ProductsError,
} from './products-types';

/** Thrown when the API refuses; carries the code the UI translates. */
export class ProductsRefused extends Error {
  constructor(readonly code: ProductsError) {
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

  async product(companyId: string, id: string): Promise<ProductRow> {
    return this.guard(async () =>
      toProduct(
        await firstValueFrom(this.http.get<ProductProductRead>(path(companyId, 'products', id))),
      ),
    );
  }

  /** 409 when another product of the company has the reference; 422 naming the field the API refused. */
  async createProduct(companyId: string, input: ProductInput): Promise<ProductRow> {
    return this.guard(async () =>
      toProduct(
        await firstValueFrom(
          this.http.post<ProductProductRead>(path(companyId, 'products'), toProductBody(input)),
        ),
      ),
    );
  }

  async reviseProduct(companyId: string, id: string, input: ProductInput): Promise<ProductRow> {
    return this.guard(async () =>
      toProduct(
        await firstValueFrom(
          this.http.put<ProductProductRead>(path(companyId, 'products', id), toProductBody(input)),
        ),
      ),
    );
  }

  /**
   * The product's codes become exactly these (docs/SPEC.md § 7, 2026-09-22 11:05). A code another product holds
   * answers 409 naming the row and that product; a malformed row answers 422 naming it. Both reach the caller as a
   * `BarcodesRefused` carrying the row.
   */
  async replaceBarcodes(
    companyId: string,
    productId: string,
    barcodes: readonly ProductBarcode[],
  ): Promise<ProductBarcode[]> {
    const body: ProductBarcodesProductBarcodesWrite = {
      barcodes: barcodes.map((row) => ({ ...row })),
    };
    try {
      const saved = await firstValueFrom(
        this.http.put<ProductBarcodesProductBarcodesRead>(
          `${path(companyId, 'products', productId)}/barcodes`,
          body,
        ),
      );
      return (saved.barcodes ?? []).map(toBarcode);
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

  /** 422 when the new parent is the category itself or one of its subcategories. */
  async reviseCategory(
    companyId: string,
    id: string,
    input: ProductCategoryInput,
  ): Promise<ProductCategoryRow> {
    const body: ProductCategoryProductCategoryWrite = { ...input };
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
   * Gives the product a home there. The establishment is never sent: the location already knows where it is, and a
   * product that had a home in that establishment has it moved rather than doubled.
   */
  async setHome(companyId: string, productId: string, locationId: string): Promise<ProductHomeRow> {
    return this.guard(async () =>
      toHome(
        await firstValueFrom(
          this.http.put<ProductHomeProductHomeRead>(homesPath(companyId, productId), {
            locationId,
          }),
        ),
      ),
    );
  }

  /** Takes the home away in that establishment; one the product never had is not an error. */
  async clearHome(companyId: string, productId: string, establishmentId: string): Promise<void> {
    await this.guard(async () =>
      firstValueFrom(
        this.http.delete(
          `${homesPath(companyId, productId)}/${encodeURIComponent(establishmentId)}`,
        ),
      ),
    );
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
}

function codeOf(error: unknown, conflict: ProductsError): ProductsError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return 'network';
  }
  switch (error.status) {
    case 404:
      return 'not_found';
    case 409:
      return conflict;
    default:
      return 'invalid';
  }
}

const homesPath = (companyId: string, productId: string): string =>
  `${path(companyId, 'products', productId)}/home-locations`;

function toHome(raw: ProductHomeProductHomeRead): ProductHomeRow {
  return {
    id: raw.id ?? '',
    establishmentId: raw.establishmentId ?? '',
    establishmentCode: raw.establishmentCode ?? '',
    establishmentName: raw.establishmentName ?? '',
    locationId: raw.locationId ?? '',
    locationCode: raw.locationCode ?? '',
    locationName: raw.locationName ?? '',
  };
}

const path = (companyId: string, collection: string, id?: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}/${collection}${id === undefined ? '' : `/${encodeURIComponent(id)}`}`;

function toSearchParams(search: ProductSearch): HttpParams {
  let params = new HttpParams().set('page', search.page).set('itemsPerPage', search.itemsPerPage);
  if (search.q.trim() !== '') params = params.set('q', search.q.trim());
  if (search.kind !== null) params = params.set('kind', search.kind);
  if (search.isActive !== null) params = params.set('isActive', search.isActive);
  if (search.order !== null)
    params = params.set(`order[${search.order.key}]`, search.order.direction);
  return params;
}

function toProduct(raw: ProductProductRead | ProductJsonldProductRead): ProductRow {
  return {
    id: raw.id ?? '',
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
      return family === undefined ? [] : [{ id: tax.id, code: tax.code, name: tax.name, family }];
    }),
  };
}

function toCategory(raw: ProductCategoryProductCategoryRead): ProductCategoryRow {
  return {
    id: raw.id ?? '',
    name: raw.name ?? '',
    parentId: raw.parentId ?? null,
    productCount: raw.productCount ?? 0,
    childCount: raw.childCount ?? 0,
  };
}
