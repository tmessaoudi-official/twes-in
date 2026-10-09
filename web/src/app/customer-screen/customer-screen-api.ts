// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  EstablishmentEstablishmentRead,
  CustomerScreenAvailabilityCustomerScreenAvailabilityRead,
  CustomerScreenProductsCustomerScreenProductsRead,
  CustomerScreenPromotionsCustomerScreenPromotionsRead,
} from '../api/types.gen';
import type { ScreenPlace, ScreenProduct } from './customer-screen-types';

const path = (companyId: string, resource: string): string =>
  `/api/companies/${companyId}/customer-screen/${resource}`;

/**
 * The screen's own way to a product's main photo, under the one prefix the screen's hold lets through: the clerk's
 * gallery is out of its reach. The large copy, read across a counter.
 */
const photoPath = (companyId: string, productId: string, photoId: string): string =>
  path(
    companyId,
    `products/${encodeURIComponent(productId)}/photos/${encodeURIComponent(photoId)}?size=large`,
  );

/**
 * The customer screen's three answers, one from each module that knows a piece of it (the only importer of the
 * generated types here): the products found, then the promotions and the stock of those products. A module switched
 * off answers 404, which reads as "nothing to say"; any other failure is let through, because an answer left out by an
 * error would be a price or a stock shown wrong.
 */
@Injectable({ providedIn: 'root' })
export class CustomerScreenApi {
  private readonly http = inject(HttpClient);

  /**
   * The establishments the screen may stand at. Reading them takes company.read; somebody without it is answered 404,
   * which reads as no choice to make, and the screen then says the default establishment's stock.
   */
  async places(companyId: string): Promise<ScreenPlace[]> {
    try {
      const rows = await firstValueFrom(
        this.http.get<EstablishmentEstablishmentRead[]>(
          `/api/companies/${companyId}/establishments`,
        ),
      );
      return rows.map((row) => ({
        id: row.id ?? '',
        code: row.code ?? '',
        name: row.name ?? '',
        isDefault: row.isDefault ?? false,
      }));
    } catch (error) {
      if (error instanceof HttpErrorResponse && error.status === 404) return [];
      throw error;
    }
  }

  /** The products the words find, their stock read at the establishment named, or the default one when none is. */
  async find(companyId: string, words: string, placeId: string | null): Promise<ScreenProduct[]> {
    const found = await firstValueFrom(
      this.http.get<CustomerScreenProductsCustomerScreenProductsRead>(path(companyId, 'products'), {
        params: new HttpParams().set('q', words),
      }),
    );
    if (found.items.length === 0) return [];
    const ids = found.items.map((item) => item.id);
    const [promotions, availability] = await Promise.all([
      this.optional<CustomerScreenPromotionsCustomerScreenPromotionsRead>(
        companyId,
        'promotions',
        ids,
      ),
      this.optional<CustomerScreenAvailabilityCustomerScreenAvailabilityRead>(
        companyId,
        'availability',
        ids,
        placeId,
      ),
    ]);
    return found.items.map((item) => {
      const stock = availability?.items.find((row) => row.productId === item.id);
      return {
        id: item.id,
        name: item.name,
        reference: item.reference,
        barcode: item.barcode,
        finalPrice: item.finalPrice,
        photo: item.photoId === null ? null : photoPath(companyId, item.id, item.photoId),
        inStock: stock?.inStock ?? null,
        promotions: (promotions?.items ?? [])
          .filter((row) => row.productId === item.id)
          .map((row) => ({
            price: row.price,
            minQuantity: row.minQuantity,
            startsOn: row.startsOn,
            endsOn: row.endsOn,
          })),
      };
    });
  }

  private async optional<T>(
    companyId: string,
    resource: string,
    ids: string[],
    placeId: string | null = null,
  ): Promise<T | null> {
    let params =
      placeId === null ? new HttpParams() : new HttpParams().set('establishmentId', placeId);
    for (const id of ids) params = params.append('ids[]', id);
    try {
      return await firstValueFrom(this.http.get<T>(path(companyId, resource), { params }));
    } catch (error) {
      // The module behind this answer is switched off for the company, which the API answers with the 404 of a
      // module that is not there (docs/SPEC.md § 3 Modules); nothing else is swallowed.
      if (error instanceof HttpErrorResponse && error.status === 404) return null;
      throw error;
    }
  }
}
