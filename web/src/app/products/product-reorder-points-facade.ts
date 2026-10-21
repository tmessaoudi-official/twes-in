// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { ProductsApi, ProductsRefused } from './products-api';
import { ProductsFacade } from './products-facade';
import type { ProductReorderPointRow, ProductsError } from './products-types';
import { QuietReads } from '../shared/feedback/quiet-reads';

/** What became of a write: `changed` is a product saved since, which the product page reads again and says. */
export type ReorderPointWrite = 'saved' | 'refused' | 'changed';

/**
 * One product's reorder point in each establishment (docs/SPEC.md § 7, 2026-09-24 11:40). Its own facade, as the homes
 * have theirs: a section that fails to load or is saving says so about itself, not about the whole product screen.
 */
@Injectable()
export class ProductReorderPoints {
  private readonly api = inject(ProductsApi);
  private readonly quietReads = inject(QuietReads);
  private readonly products = inject(ProductsFacade);
  private readonly rowsSignal = signal<readonly ProductReorderPointRow[]>([]);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<ProductsError | null>(null);

  readonly rows = this.rowsSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  async load(companyId: string, productId: string): Promise<void> {
    await this.run(
      async () => this.rowsSignal.set(await this.api.reorderPoints(companyId, productId)),
      true,
    );
  }

  /**
   * Sets the point on the product's version the points were read at, or clears it with no quantity: they are shown
   * beside the product form and move it. The version the write left is the product's before this says it is done, so
   * a change straight after sends it. Over a product saved since, the points are read again and what was typed is the
   * screen's to keep.
   */
  async set(
    companyId: string,
    productId: string,
    establishmentId: string,
    quantity: string | null,
    version: number,
  ): Promise<ReorderPointWrite> {
    const done = await this.run(async () => {
      this.products.versionMoved(
        productId,
        await this.api.setReorderPoint(companyId, productId, establishmentId, quantity, version),
      );
      this.rowsSignal.set(await this.api.reorderPoints(companyId, productId));
    });
    if (done) return 'saved';
    if (this.errorSignal() !== 'record_changed') return 'refused';
    this.errorSignal.set(null);
    await this.load(companyId, productId);
    return 'changed';
  }

  /** `reading` marks a read: one nobody asked for leaves busy as it is (QuietReads); a write is always marked. */
  private async run(work: () => Promise<void>, reading = false): Promise<boolean> {
    const marked = !(reading && this.quietReads.quiet());
    if (marked) this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      await work();
      return true;
    } catch (error) {
      this.errorSignal.set(error instanceof ProductsRefused ? error.code : 'network');
      return false;
    } finally {
      if (marked) this.busySignal.set(false);
    }
  }
}
