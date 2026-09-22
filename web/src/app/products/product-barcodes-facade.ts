// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { VendorsApi } from '../vendors/vendors-api';
import { BarcodesRefused, ProductsApi } from './products-api';
import { ProductsFacade } from './products-facade';
import type { BarcodesRefusal, ProductBarcode } from './products-types';

/** A supplier a supplier's code can name. */
export interface SupplierChoice {
  id: string;
  label: string;
}

/**
 * The codes of one product (docs/SPEC.md § 7, 2026-09-22 11:05), saved on their own like the product's other tabs.
 *
 * Its own facade, as the homes tab has one: a refused code says so under its row, instead of putting the whole
 * product screen in an error. What the product holds is still `ProductsFacade.product()`; a save writes the answer
 * back there, so the record and this tab never disagree about what is saved.
 */
@Injectable()
export class ProductBarcodes {
  private readonly api = inject(ProductsApi);
  private readonly vendors = inject(VendorsApi);
  private readonly products = inject(ProductsFacade);
  private readonly busySignal = signal(false);
  private readonly refusalSignal = signal<BarcodesRefusal | null>(null);
  private readonly suppliersSignal = signal<readonly SupplierChoice[]>([]);

  readonly busy = this.busySignal.asReadonly();
  readonly refusal = this.refusalSignal.asReadonly();
  readonly suppliers = this.suppliersSignal.asReadonly();

  /**
   * The company's active suppliers a supplier's code may name, the first hundred. Asked only where the vendors module
   * is on and the person may read vendors: the screen decides, since only it knows both.
   */
  async loadSuppliers(companyId: string): Promise<void> {
    try {
      const page = await this.vendors.vendors(companyId, {
        page: 1,
        itemsPerPage: 100,
        q: '',
        isActive: true,
        order: null,
      });
      this.suppliersSignal.set(
        page.rows.map((vendor) => ({ id: vendor.id, label: `${vendor.number} · ${vendor.name}` })),
      );
    } catch {
      this.refusalSignal.set({ code: 'network', index: null, field: null, heldBy: null });
    }
  }

  /** Makes the product's codes exactly these; false with the refusal kept when the API said no. */
  async save(
    companyId: string,
    productId: string,
    rows: readonly ProductBarcode[],
  ): Promise<boolean> {
    this.busySignal.set(true);
    this.refusalSignal.set(null);
    try {
      this.products.barcodesSaved(
        productId,
        await this.api.replaceBarcodes(companyId, productId, rows),
      );
      return true;
    } catch (error) {
      this.refusalSignal.set(
        error instanceof BarcodesRefused
          ? error.refusal
          : { code: 'network', index: null, field: null, heldBy: null },
      );
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }

  forget(): void {
    this.refusalSignal.set(null);
  }
}
