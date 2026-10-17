// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { ProductsApi, ProductsRefused } from './products-api';
import type { ProductReorderPointRow, ProductsError } from './products-types';

/** What became of a write: `changed` is a product saved since, which the product page reads again and says. */
export type ReorderPointWrite = 'saved' | 'refused' | 'changed';

/**
 * One product's reorder point in each establishment (docs/SPEC.md § 7, 2026-09-24 11:40). Its own facade, as the homes
 * have theirs: a section that fails to load or is saving says so about itself, not about the whole product screen.
 */
@Injectable()
export class ProductReorderPoints {
  private readonly api = inject(ProductsApi);
  private readonly rowsSignal = signal<readonly ProductReorderPointRow[]>([]);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<ProductsError | null>(null);

  readonly rows = this.rowsSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  async load(companyId: string, productId: string): Promise<void> {
    await this.run(async () =>
      this.rowsSignal.set(await this.api.reorderPoints(companyId, productId)),
    );
  }

  /**
   * Sets the point on the product's version the points were read at: they are shown beside the product form and move
   * it. Over a product saved since, the points are read again and what was typed is the screen's to keep.
   */
  async set(
    companyId: string,
    productId: string,
    establishmentId: string,
    quantity: string,
    version: number,
  ): Promise<ReorderPointWrite> {
    const done = await this.run(async () => {
      await this.api.setReorderPoint(companyId, productId, establishmentId, quantity, version);
      this.rowsSignal.set(await this.api.reorderPoints(companyId, productId));
    });
    if (done) return 'saved';
    if (this.errorSignal() !== 'record_changed') return 'refused';
    this.errorSignal.set(null);
    await this.load(companyId, productId);
    return 'changed';
  }

  async clear(
    companyId: string,
    productId: string,
    establishmentId: string,
  ): Promise<ReorderPointWrite> {
    const done = await this.run(async () => {
      await this.api.clearReorderPoint(companyId, productId, establishmentId);
      this.rowsSignal.set(await this.api.reorderPoints(companyId, productId));
    });
    return done ? 'saved' : 'refused';
  }

  private async run(work: () => Promise<void>): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      await work();
      return true;
    } catch (error) {
      this.errorSignal.set(error instanceof ProductsRefused ? error.code : 'network');
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }
}
