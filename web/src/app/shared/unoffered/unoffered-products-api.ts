// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  DeliveryNoteUnofferedProductsResponse,
  InvoiceUnofferedProductsResponse,
  PurchaseOrderUnofferedProductsResponse,
  QuoteUnofferedProductsResponse,
  StockUnofferedProductsResponse,
} from '../../api/types.gen';
import { typedText } from '../form/typed-text';

/**
 * What the words typed in a product selector find that the selector does not offer, each count 0 when none: the
 * products switched off, the services where goods alone are offered, the goods whose stock is not kept. Read from each
 * selector's own answer, so a count the API renames or drops is a type error here.
 */
export type UnofferedCounts = Readonly<
  | InvoiceUnofferedProductsResponse
  | QuoteUnofferedProductsResponse
  | DeliveryNoteUnofferedProductsResponse
  | PurchaseOrderUnofferedProductsResponse
  | StockUnofferedProductsResponse
>;

/** Every product selector whose API answers `…/products/unoffered` beside its picker, by the options it is read under. */
export type ProductSelector =
  | 'invoice-options'
  | 'quote-options'
  | 'delivery-note-options'
  | 'purchase-order-options'
  | 'stock-options';

/**
 * Asks a selector's API what the words find that it left out. One shape on every selector, read under that selector's
 * own permission, so it lives here rather than in each feature's API.
 */
@Injectable({ providedIn: 'root' })
export class UnofferedProductsApi {
  private readonly http = inject(HttpClient);

  unoffered(companyId: string, selector: ProductSelector, words: string): Promise<UnofferedCounts> {
    return firstValueFrom(
      this.http.get<UnofferedCounts>(
        `/api/companies/${encodeURIComponent(companyId)}/${selector}/products/unoffered`,
        { params: new HttpParams().set('q', typedText(words)) },
      ),
    );
  }
}
