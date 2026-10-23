// SPDX-License-Identifier: AGPL-3.0-or-later

import { LatestRequest, requestState } from '../shared/feedback/request-state';
import { inject, Injectable } from '@angular/core';
import type { PickAsked } from '../shared/form/pick-api';
import { PurchasesApi, PurchasesRefused } from './purchases-api';
import type {
  ProductOption,
  PurchaseOrderInput,
  PurchaseOrderRow,
  PurchaseOrderSearch,
  PurchaseOrderStatusCounts,
  PurchasesError,
  ReceiptInput,
  Receiving,
  VendorOption,
} from './purchases-types';
import { sessionSignal } from '../shared/session/session-data';

/** The orders to vendors of the company being worked in, and the order open on screen. */
@Injectable({ providedIn: 'root' })
export class PurchasesFacade {
  private readonly api = inject(PurchasesApi);
  private readonly ordersSignal = sessionSignal<readonly PurchaseOrderRow[]>([]);
  private readonly orderSignal = sessionSignal<PurchaseOrderRow | null>(null);
  private readonly totalSignal = sessionSignal(0);
  private readonly statusCountsSignal = sessionSignal<PurchaseOrderStatusCounts | null>(null);
  private readonly pageRequests = new LatestRequest();
  private readonly countsRequests = new LatestRequest();
  private readonly state = requestState(codeOf);
  private readonly refusedSignal = sessionSignal<string | null>(null);

  readonly orders = this.ordersSignal.asReadonly();
  /** The order open on screen, as the API last answered with it; null while a new one is filled in. */
  readonly order = this.orderSignal.asReadonly();
  /** How many orders the last search found in all, the page shown being one part of them. */
  readonly total = this.totalSignal.asReadonly();
  /** What each status chip of the list would show; null until read, and kept while a new count is on its way. */
  readonly statusCounts = this.statusCountsSignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;
  /** The field the last step was refused on, when the API named it: `lines[1].quantity`, `vendorId`… */
  readonly refused = this.refusedSignal.asReadonly();

  /**
   * One page of the orders the search finds. Only the latest search's answer is shown: typing sends one search per
   * keystroke and they need not come back in order.
   */
  async loadPage(companyId: string, search: PurchaseOrderSearch): Promise<void> {
    const current = this.pageRequests.next();
    await this.read(async () => {
      const page = await this.api.orders(companyId, search);
      if (!current()) return;
      this.ordersSignal.set(page.rows);
      this.totalSignal.set(page.total);
    });
  }

  /** The chips' counts for the search, the latest search's answer only, as for its page. */
  async loadStatusCounts(companyId: string, search: PurchaseOrderSearch): Promise<void> {
    const current = this.countsRequests.next();
    await this.read(async () => {
      const counts = await this.api.statusCounts(companyId, search);
      if (current()) this.statusCountsSignal.set(counts);
    });
  }

  /**
   * The few vendors or products a person means while typing, and — by id — exactly the records an open order names. A
   * search that fails answers nothing and says so in `error`, rather than reading as "nothing found".
   */
  async pickVendors(companyId: string, asked: PickAsked): Promise<VendorOption[]> {
    return this.pick(() => this.api.pickVendors(companyId, asked));
  }

  async pickProducts(companyId: string, asked: PickAsked): Promise<ProductOption[]> {
    return this.pick(() => this.api.pickProducts(companyId, asked));
  }

  /** The order, or nothing for a new one. */
  async loadOrder(companyId: string, id: string | null): Promise<void> {
    await this.read(async () =>
      this.orderSignal.set(id === null ? null : await this.api.order(companyId, id)),
    );
  }

  /** Where an order's PDF is downloaded from; its prices are costs, which only a cost reader is answered. */
  pdfUrl(companyId: string, id: string): string {
    return this.api.pdfUrl(companyId, id);
  }

  /** The draft as the API kept it, or null with the reason in `error`. */
  async create(companyId: string, input: PurchaseOrderInput): Promise<PurchaseOrderRow | null> {
    return this.step(() => this.api.create(companyId, input));
  }

  async revise(
    companyId: string,
    id: string,
    input: PurchaseOrderInput,
    version: number | null,
  ): Promise<PurchaseOrderRow | null> {
    return this.step(() => this.api.revise(companyId, id, input, version));
  }

  /**
   * Saves what is on screen, then sends it: an order never goes to its vendor with content other than the one shown.
   * `revised` is told once the save is kept, whatever sending then answers, so the form stands on what was saved.
   */
  async reviseAndSend(
    companyId: string,
    id: string,
    input: PurchaseOrderInput,
    version: number | null,
    revised: () => void,
  ): Promise<PurchaseOrderRow | null> {
    return this.step(async () => {
      this.orderSignal.set(await this.api.revise(companyId, id, input, version));
      revised();
      return this.api.send(companyId, id);
    });
  }

  async close(companyId: string, id: string): Promise<PurchaseOrderRow | null> {
    return this.step(() => this.api.close(companyId, id));
  }

  async cancel(companyId: string, id: string): Promise<PurchaseOrderRow | null> {
    return this.step(() => this.api.cancel(companyId, id));
  }

  /**
   * Where the order's goods are offered to go, or null with the reason in `error`: the receipt cannot be offered
   * without the places it puts goods in.
   */
  async receiving(companyId: string, id: string): Promise<Receiving | null> {
    this.state.clearError();
    try {
      return await this.api.receiving(companyId, id);
    } catch (error) {
      this.state.fail(error);
      return null;
    }
  }

  /** The order as the receipt left it, or null with the reason in `error` and the line it names in `refused`. */
  async receive(
    companyId: string,
    id: string,
    input: ReceiptInput,
  ): Promise<PurchaseOrderRow | null> {
    // What the receipt did to the products' prices or margins is said from its own answer (`PriceEffects`).
    return this.step(() => this.api.receive(companyId, id, input));
  }

  clearError(): void {
    this.state.clearError();
    this.refusedSignal.set(null);
  }

  /** A picker's own call: it never marks the screen busy, because a person is typing while it runs. */
  private async pick<T>(call: () => Promise<T[]>): Promise<T[]> {
    try {
      return await call();
    } catch (error) {
      this.state.fail(error);
      return [];
    }
  }

  private async read(load: () => Promise<void>): Promise<void> {
    await this.state.read(load);
  }

  private async step(call: () => Promise<PurchaseOrderRow>): Promise<PurchaseOrderRow | null> {
    this.refusedSignal.set(null);
    return this.state.writeAnswer(
      async () => {
        const order = await call();
        this.orderSignal.set(order);
        return order;
      },
      {
        failed: (error) =>
          this.refusedSignal.set(error instanceof PurchasesRefused ? error.field : null),
      },
    );
  }
}

function codeOf(error: unknown): PurchasesError {
  return error instanceof PurchasesRefused ? error.code : 'network';
}
