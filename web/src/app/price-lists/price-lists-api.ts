// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { isRecordChanged } from '../shared/errors/record-changed';
import type {
  PriceListPriceListRead,
  PriceListPriceListWriteRecordReviseValidationPriceListWriteRecordRevise as PriceListRevise,
  PriceListPriceListWriteValidationPriceListWrite as PriceListPriceListWrite,
  ProductPriceProductPriceRead,
} from '../api/types.gen';
import type {
  PriceListInput,
  PriceListRow,
  PriceListsError,
  ResolvedPrice,
} from './price-lists-types';
import { refusedFor } from '../shared/errors/said-already';

/** Thrown when the API refuses; carries the code the UI translates. */
export class PriceListsRefused extends Error {
  constructor(readonly code: PriceListsError) {
    super(code);
  }
}

const path = (companyId: string, id?: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}/price-lists${
    id === undefined ? '' : `/${encodeURIComponent(id)}`
  }`;

/** The HTTP edge of the price lists: the only code here that knows endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class PriceListsApi {
  private readonly http = inject(HttpClient);

  /**
   * The net unit price a sale of the product starts at for that customer and quantity, and the list that set it (null
   * for the shelf price). A price that cannot be read is null: the line keeps the price it has.
   */
  async productPrice(
    companyId: string,
    productId: string,
    customerId: string | null,
    quantity: string,
  ): Promise<ResolvedPrice | null> {
    let params = new HttpParams().set('quantity', quantity);
    if (customerId !== null) params = params.set('customerId', customerId);
    try {
      const read = await firstValueFrom(
        this.http.get<ProductPriceProductPriceRead>(
          `/api/companies/${encodeURIComponent(companyId)}/products/${encodeURIComponent(productId)}/price`,
          { params },
        ),
      );
      return { unitPriceNet: read.unitPriceNet, priceListName: read.priceListName ?? null };
    } catch {
      return null;
    }
  }

  /** Every list, each with the number of its prices and none of them. */
  list(companyId: string): Promise<PriceListRow[]> {
    return this.guard(async () =>
      (await firstValueFrom(this.http.get<PriceListPriceListRead[]>(path(companyId)))).map(toRow),
    );
  }

  /** One list with its prices. */
  get(companyId: string, id: string): Promise<PriceListRow> {
    return this.guard(async () =>
      toRow(await firstValueFrom(this.http.get<PriceListPriceListRead>(path(companyId, id)))),
    );
  }

  create(companyId: string, input: PriceListInput): Promise<PriceListRow> {
    return this.guard(async () =>
      toRow(
        await firstValueFrom(this.http.post<PriceListPriceListRead>(path(companyId), body(input))),
      ),
    );
  }

  /** `version` is the one the list was opened at: a later one saved elsewhere answers `record_changed`. */
  revise(
    companyId: string,
    id: string,
    input: PriceListInput,
    version: number,
  ): Promise<PriceListRow> {
    const revision: PriceListRevise = { ...body(input), version };
    return this.guard(async () =>
      toRow(
        await firstValueFrom(this.http.put<PriceListPriceListRead>(path(companyId, id), revision)),
      ),
    );
  }

  private async guard<T>(call: () => Promise<T>): Promise<T> {
    try {
      return await call();
    } catch (error) {
      throw refusedFor(error, new PriceListsRefused(codeOf(error)));
    }
  }
}

function body(input: PriceListInput): PriceListPriceListWrite {
  return {
    name: input.name,
    customerGroupId: input.customerGroupId,
    customerId: input.customerId,
    validFrom: input.validFrom,
    validTo: input.validTo,
    isActive: input.isActive,
    ...(input.items === null
      ? {}
      : {
          items: input.items.map((item) => ({
            productId: item.productId,
            minQuantity: item.minQuantity,
            unitPriceNet: item.unitPriceNet,
          })),
        }),
  };
}

function toRow(read: PriceListPriceListRead): PriceListRow {
  return {
    id: read.id ?? '',
    name: read.name ?? '',
    customerGroupId: read.customerGroupId ?? null,
    customerId: read.customerId ?? null,
    validFrom: read.validFrom ?? null,
    validTo: read.validTo ?? null,
    isActive: read.isActive ?? true,
    itemCount: read.itemCount ?? 0,
    // Every read carries it; a 0 would be refused by the API rather than taken for a version.
    version: read.version ?? 0,
    items:
      read.items === undefined
        ? null
        : read.items.map((item) => ({
            productId: item.productId ?? '',
            productReference: item.productReference ?? '',
            productName: item.productName ?? '',
            minQuantity: item.minQuantity ?? '',
            unitPriceNet: item.unitPriceNet ?? '',
          })),
  };
}

function codeOf(error: unknown): PriceListsError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) return 'network';
  switch (error.status) {
    case 403:
      return 'refused';
    case 404:
      return 'not_found';
    case 409:
      return isRecordChanged(error.error) ? 'record_changed' : 'name_taken';
    default:
      return 'invalid';
  }
}
