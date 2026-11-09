// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { typedText } from '../form/typed-text';

/** What the words typed in a product selector find that the selector does not offer, each count 0 when none. */
export interface UnofferedCounts {
  /** Products switched off, of the kind the selector offers. */
  readonly deactivated: number;
  /** Services, where the selector offers goods alone (an order, the stock). */
  readonly services: number;
  /** Active goods whose stock is not kept, which the stock's selectors leave out. */
  readonly notStocked: number;
}

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
