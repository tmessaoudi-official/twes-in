// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, inject, Injectable, signal } from '@angular/core';
import { requestState } from '../shared/feedback/request-state';
import { VendorsApi, VendorsRefused } from './vendors-api';
import type { VendorPurchaseRow, VendorsError } from './vendors-types';

/** How many lines « Achats » reads at a time: one vendor sold this shop 1,024 lines. */
export const VENDOR_PURCHASES_PAGE = 50;

/**
 * What was bought from one vendor, a page at a time, each « Afficher plus » adding the next. Its own facade, as a
 * product's cost history has its own: a tab that fails to load says so about itself, not about the vendor's screen.
 */
@Injectable()
export class VendorPurchases {
  private readonly api = inject(VendorsApi);
  private readonly rowsSignal = signal<readonly VendorPurchaseRow[]>([]);
  private readonly totalSignal = signal<number | null>(null);
  private readonly state = requestState<VendorsError>((error) =>
    error instanceof VendorsRefused ? error.code : 'network',
  );
  private nextPage = 1;

  readonly rows = this.rowsSignal.asReadonly();
  /** How many lines the vendor has in all; null until the first page is read. */
  readonly total = this.totalSignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;
  readonly more = computed(() => {
    const total = this.totalSignal();
    return total !== null && this.rowsSignal().length < total;
  });

  async load(companyId: string, vendorId: string): Promise<void> {
    await this.state.read(async () => {
      const page = await this.api.purchases(companyId, vendorId, 1, VENDOR_PURCHASES_PAGE);
      this.rowsSignal.set(page.rows);
      this.totalSignal.set(page.total);
      this.nextPage = 2;
    });
  }

  async loadMore(companyId: string, vendorId: string): Promise<void> {
    if (this.busy() || !this.more()) return;
    await this.state.read(async () => {
      const page = await this.api.purchases(
        companyId,
        vendorId,
        this.nextPage,
        VENDOR_PURCHASES_PAGE,
      );
      this.rowsSignal.update((rows) => [...rows, ...page.rows]);
      this.totalSignal.set(page.total);
      this.nextPage += 1;
    });
  }
}
