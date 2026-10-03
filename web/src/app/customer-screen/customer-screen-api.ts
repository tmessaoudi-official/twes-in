// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  CustomerScreenAvailabilityCustomerScreenAvailabilityRead,
  CustomerScreenProductsCustomerScreenProductsRead,
  CustomerScreenPromotionsCustomerScreenPromotionsRead,
} from '../api/types.gen';
import type { ScreenProduct } from './customer-screen-types';

const path = (companyId: string, resource: string): string =>
  `/api/companies/${companyId}/customer-screen/${resource}`;

/**
 * The customer screen's three answers, one from each module that knows a piece of it (the only importer of the
 * generated types here): the products found, then the promotions and the stock of those products. A module switched
 * off answers 404, which reads as "nothing to say"; any other failure is let through, because an answer left out by an
 * error would be a price or a stock shown wrong.
 */
@Injectable({ providedIn: 'root' })
export class CustomerScreenApi {
  private readonly http = inject(HttpClient);

  async find(companyId: string, words: string): Promise<ScreenProduct[]> {
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

  private async optional<T>(companyId: string, resource: string, ids: string[]): Promise<T | null> {
    let params = new HttpParams();
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
