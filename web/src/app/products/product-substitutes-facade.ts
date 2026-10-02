// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { ProductsApi, ProductsRefused } from './products-api';
import type { ProductsError, ProductSubstituteRow } from './products-types';

/**
 * The products that can stand in for one, each with what is on hand of it. Its own facade, as the homes have theirs: a
 * section that fails to load says so about itself, not about the whole product screen.
 */
@Injectable()
export class ProductSubstitutes {
  private readonly api = inject(ProductsApi);
  private readonly rowsSignal = signal<readonly ProductSubstituteRow[]>([]);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<ProductsError | null>(null);

  readonly rows = this.rowsSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  /**
   * The substitutes, then their stock: stock is another right, so a person without it still sees the substitutes, with
   * no quantity, rather than an error about a number they were never meant to read.
   */
  async load(companyId: string, productId: string): Promise<void> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      const substitutes = await this.api.substitutes(companyId, productId);
      this.rowsSignal.set(substitutes);
      try {
        const totals = await this.api.stockTotals(
          companyId,
          substitutes.map((row) => row.id),
        );
        this.rowsSignal.set(
          substitutes.map((row) => ({ ...row, onHand: totals.get(row.id) ?? null })),
        );
      } catch {
        // Kept without a quantity: the stock right is not this section's to demand.
      }
    } catch (error) {
      this.errorSignal.set(error instanceof ProductsRefused ? error.code : 'network');
    } finally {
      this.busySignal.set(false);
    }
  }
}
