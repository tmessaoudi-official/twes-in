// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
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

/** The orders to vendors of the company being worked in, and the order open on screen. */
@Injectable({ providedIn: 'root' })
export class PurchasesFacade {
  private readonly api = inject(PurchasesApi);
  private readonly ordersSignal = signal<readonly PurchaseOrderRow[]>([]);
  private readonly orderSignal = signal<PurchaseOrderRow | null>(null);
  private readonly totalSignal = signal(0);
  private readonly statusCountsSignal = signal<PurchaseOrderStatusCounts | null>(null);
  private pageRequest = 0;
  private countsRequest = 0;
  private readonly busySignal = signal(false);
  private reads = 0;
  private readonly errorSignal = signal<PurchasesError | null>(null);
  private readonly refusedSignal = signal<string | null>(null);

  readonly orders = this.ordersSignal.asReadonly();
  /** The order open on screen, as the API last answered with it; null while a new one is filled in. */
  readonly order = this.orderSignal.asReadonly();
  /** How many orders the last search found in all, the page shown being one part of them. */
  readonly total = this.totalSignal.asReadonly();
  /** What each status chip of the list would show; null until read, and kept while a new count is on its way. */
  readonly statusCounts = this.statusCountsSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();
  /** The field the last step was refused on, when the API named it: `lines[1].quantity`, `vendorId`… */
  readonly refused = this.refusedSignal.asReadonly();

  /**
   * One page of the orders the search finds. Only the latest search's answer is shown: typing sends one search per
   * keystroke and they need not come back in order.
   */
  async loadPage(companyId: string, search: PurchaseOrderSearch): Promise<void> {
    const request = ++this.pageRequest;
    await this.read(async () => {
      const page = await this.api.orders(companyId, search);
      if (request !== this.pageRequest) return;
      this.ordersSignal.set(page.rows);
      this.totalSignal.set(page.total);
    });
  }

  /** The chips' counts for the search, the latest search's answer only, as for its page. */
  async loadStatusCounts(companyId: string, search: PurchaseOrderSearch): Promise<void> {
    const request = ++this.countsRequest;
    await this.read(async () => {
      const counts = await this.api.statusCounts(companyId, search);
      if (request === this.countsRequest) this.statusCountsSignal.set(counts);
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
    this.errorSignal.set(null);
    try {
      return await this.api.receiving(companyId, id);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return null;
    }
  }

  /** The order as the receipt left it, or null with the reason in `error` and the line it names in `refused`. */
  async receive(
    companyId: string,
    id: string,
    input: ReceiptInput,
  ): Promise<PurchaseOrderRow | null> {
    return this.step(() => this.api.receive(companyId, id, input));
  }

  clearError(): void {
    this.errorSignal.set(null);
    this.refusedSignal.set(null);
  }

  /** A picker's own call: it never marks the screen busy, because a person is typing while it runs. */
  private async pick<T>(call: () => Promise<T[]>): Promise<T[]> {
    try {
      return await call();
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return [];
    }
  }

  private async read(load: () => Promise<void>): Promise<void> {
    this.reads++;
    this.busySignal.set(true);
    // Cleared when a read starts, never when one ends, so a read answering after another failed does not hide it.
    this.errorSignal.set(null);
    try {
      await load();
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      // Several reads run at once (a list and its counts): busy until the last one answers.
      if (--this.reads === 0) this.busySignal.set(false);
    }
  }

  private async step(call: () => Promise<PurchaseOrderRow>): Promise<PurchaseOrderRow | null> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    this.refusedSignal.set(null);
    try {
      const order = await call();
      this.orderSignal.set(order);
      return order;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      this.refusedSignal.set(error instanceof PurchasesRefused ? error.field : null);
      return null;
    } finally {
      this.busySignal.set(false);
    }
  }
}

function codeOf(error: unknown): PurchasesError {
  return error instanceof PurchasesRefused ? error.code : 'network';
}
