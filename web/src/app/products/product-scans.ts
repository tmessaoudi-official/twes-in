// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { AuthFacade } from '../auth/auth-facade';
import { ProductsApi, ProductsRefused } from './products-api';
import type { ProductScan } from './products-types';

/**
 * What a scan into a document line counts (docs/SPEC.md § 7, 2026-09-23 00:40): the picker has already put the
 * product on the line, and a pack's code enters the pieces it holds. The document screens ask here rather than the
 * pickers carrying a count on every row, because only a scan has one.
 */
@Injectable({ providedIn: 'root' })
export class ProductScans {
  private readonly api = inject(ProductsApi);
  private readonly auth = inject(AuthFacade);

  /**
   * What a scan names, for a screen that acts on it (docs/SPEC.md § 7, 2026-09-23 09:30); null when no product of the
   * company answers to the code. A lookup that failed throws: "not found" would offer to create what exists.
   */
  async named(code: string): Promise<ProductScan | null> {
    const companyId = this.auth.me()?.company?.id;
    if (companyId === undefined) return null;
    return this.api.scan(companyId, code);
  }

  /**
   * The pieces one scan of `code` enters when it is a pack of `productId`; null for a single piece, a code of
   * another product, a code nobody holds, or a lookup that failed — in each the line keeps the quantity it has.
   */
  async piecesPerScan(code: string, productId: string): Promise<number | null> {
    const companyId = this.auth.me()?.company?.id;
    if (companyId === undefined) return null;
    let scan;
    try {
      scan = await this.api.scan(companyId, code);
    } catch (error) {
      // The product is on the line already; a count the API could not give is left to the person, not guessed.
      if (error instanceof ProductsRefused) return null;
      throw error;
    }
    return scan !== null && scan.productId === productId && scan.quantity > 1
      ? scan.quantity
      : null;
  }
}
