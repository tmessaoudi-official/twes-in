// SPDX-License-Identifier: AGPL-3.0-or-later

import { requestState } from '../shared/feedback/request-state';
import { inject, Injectable, signal } from '@angular/core';
import { ProductsApi, ProductsRefused } from './products-api';
import type { ProductCostChangeRow, ProductsError } from './products-types';

/**
 * What a product has cost the company over time. Its own facade, as the substitutes have theirs: a section that fails
 * to load says so about itself, not about the whole product screen.
 */
@Injectable()
export class ProductCostHistory {
  private readonly api = inject(ProductsApi);
  private readonly rowsSignal = signal<readonly ProductCostChangeRow[]>([]);
  private readonly state = requestState<ProductsError>((error) =>
    error instanceof ProductsRefused ? error.code : 'network',
  );

  readonly rows = this.rowsSignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;

  async load(companyId: string, productId: string): Promise<void> {
    await this.state.read(async () =>
      this.rowsSignal.set(await this.api.costHistory(companyId, productId)),
    );
  }
}
