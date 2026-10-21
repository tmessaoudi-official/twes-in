// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { ProductsApi, ProductsRefused } from './products-api';
import type { ProductCostChangeRow, ProductsError } from './products-types';
import { QuietReads } from '../shared/feedback/quiet-reads';

/**
 * What a product has cost the company over time. Its own facade, as the substitutes have theirs: a section that fails
 * to load says so about itself, not about the whole product screen.
 */
@Injectable()
export class ProductCostHistory {
  private readonly api = inject(ProductsApi);
  private readonly quietReads = inject(QuietReads);
  private readonly rowsSignal = signal<readonly ProductCostChangeRow[]>([]);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<ProductsError | null>(null);

  readonly rows = this.rowsSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  async load(companyId: string, productId: string): Promise<void> {
    // A reload nobody asked for leaves busy as it is, so no action turns disabled under a pointer (QuietReads).
    const marked = !this.quietReads.quiet();
    if (marked) this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      this.rowsSignal.set(await this.api.costHistory(companyId, productId));
    } catch (error) {
      this.errorSignal.set(error instanceof ProductsRefused ? error.code : 'network');
    } finally {
      if (marked) this.busySignal.set(false);
    }
  }
}
