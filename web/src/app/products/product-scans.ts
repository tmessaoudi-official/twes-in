// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, Injector } from '@angular/core';
import { AuthFacade } from '../auth/auth-facade';
import type { ProductsApi } from './products-api';
import type { ProductScan } from './products-types';

/**
 * What a scan into a document line counts (docs/SPEC.md § 7, 2026-09-23 00:40): the picker has already put the
 * product on the line, and a pack's code enters the pieces it holds. The document screens ask here rather than the
 * pickers carrying a count on every row, because only a scan has one.
 */
@Injectable({ providedIn: 'root' })
export class ProductScans {
  private readonly injector = inject(Injector);
  private readonly auth = inject(AuthFacade);

  /**
   * The products' whole API class, loaded at the first scan: the shell holds this service, and importing the class
   * statically put all of it on the first page, past its budget.
   */
  private async products(): Promise<typeof import('./products-api')> {
    return import('./products-api');
  }

  private async api(): Promise<ProductsApi> {
    const { ProductsApi } = await this.products();
    return this.injector.get(ProductsApi);
  }

  /**
   * What a scan names, for a screen that acts on it (docs/SPEC.md § 7, 2026-09-23 09:30); null when no product of the
   * company answers to the code. A lookup that failed throws: "not found" would offer to create what exists.
   */
  async named(code: string): Promise<ProductScan | null> {
    const companyId = this.auth.me()?.company?.id;
    if (companyId === undefined) return null;
    return (await this.api()).scan(companyId, code);
  }

  /**
   * The pieces one scan of `code` enters when it is a pack of `productId`; null for a single piece, a code of
   * another product, a code nobody holds, or a lookup that failed — in each the line keeps the quantity it has.
   */
  async piecesPerScan(code: string, productId: string): Promise<number | null> {
    const companyId = this.auth.me()?.company?.id;
    if (companyId === undefined) return null;
    const { ProductsRefused } = await this.products();
    let scan;
    try {
      scan = await (await this.api()).scan(companyId, code);
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
