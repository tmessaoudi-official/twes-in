// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { CustomersApi } from '../customers/customers-api';
import type { CustomerGroupRow } from '../customers/customers-types';
import { ProductsApi } from '../products/products-api';
import type { PickOption } from '../shared/form/pick-field';
import { PriceListsApi, PriceListsRefused } from './price-lists-api';
import type { ProductFigures } from './price-lists-figures';
import type { PriceListInput, PriceListRow, PriceListsError } from './price-lists-types';

/** How many products or customers a picker offers for the words typed. */
const PICK_SIZE = 10;

/** How many products a list being opened is asked the figures of; a longer list shows none beside its rows. */
const FIGURES_CAP = 100;

/** The price lists of the company being worked in, the customer groups they can be for, and the pickers an editor uses. */
@Injectable({ providedIn: 'root' })
export class PriceListsFacade {
  private readonly api = inject(PriceListsApi);
  private readonly products = inject(ProductsApi);
  private readonly customers = inject(CustomersApi);
  private readonly listsSignal = signal<readonly PriceListRow[]>([]);
  private readonly groupsSignal = signal<readonly CustomerGroupRow[]>([]);
  private readonly figuresSignal = signal<ReadonlyMap<string, ProductFigures>>(new Map());
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<PriceListsError | null>(null);

  readonly lists = this.listsSignal.asReadonly();
  readonly groups = this.groupsSignal.asReadonly();
  /** The price and cost of each product seen so far, which a row's price is read beside. */
  readonly figures = this.figuresSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  async load(companyId: string): Promise<void> {
    await this.run(async () => {
      const [lists, groups] = await Promise.all([
        this.api.list(companyId),
        this.customers.groups(companyId),
      ]);
      this.listsSignal.set(lists);
      this.groupsSignal.set(groups);
      return true;
    });
  }

  /** The list with its prices, or null when it could not be read. */
  async open(companyId: string, id: string): Promise<PriceListRow | null> {
    let found: PriceListRow | null = null;
    await this.run(async () => {
      found = await this.api.get(companyId, id);
      return true;
    });
    return found;
  }

  async create(companyId: string, input: PriceListInput): Promise<boolean> {
    return this.write(companyId, () => this.api.create(companyId, input));
  }

  async revise(companyId: string, id: string, input: PriceListInput): Promise<boolean> {
    return this.write(companyId, () => this.api.revise(companyId, id, input));
  }

  async remove(companyId: string, id: string): Promise<boolean> {
    return this.write(companyId, () => this.api.remove(companyId, id));
  }

  clearError(): void {
    this.errorSignal.set(null);
  }

  /** The few products the words name, as a picker shows them. */
  async pickProducts(companyId: string, words: string): Promise<PickOption[]> {
    const page = await this.products.products(companyId, {
      page: 1,
      itemsPerPage: PICK_SIZE,
      q: words,
      kind: null,
      isActive: true,
      order: null,
    });
    this.remember(page.rows);
    return page.rows.map((row) => ({ id: row.id, code: row.reference, name: row.name }));
  }

  /** The figures of the products a list holds, read once each; a product that cannot be read shows none. */
  async loadFigures(companyId: string, productIds: readonly string[]): Promise<void> {
    const known = this.figuresSignal();
    const missing = [...new Set(productIds)].filter((id) => !known.has(id)).slice(0, FIGURES_CAP);
    const read = await Promise.all(
      missing.map((id) => this.products.product(companyId, id).catch(() => null)),
    );
    this.remember(read.filter((row): row is NonNullable<typeof row> => row !== null));
  }

  private remember(
    rows: readonly { id: string; unitPriceNet: string; costPrice: string | null }[],
  ): void {
    this.figuresSignal.update((known) => {
      const next = new Map(known);
      for (const row of rows) {
        next.set(row.id, { unitPriceNet: row.unitPriceNet, costPrice: row.costPrice });
      }
      return next;
    });
  }

  /** The few customers the words name, as a picker shows them. */
  async pickCustomers(companyId: string, words: string): Promise<PickOption[]> {
    const page = await this.customers.customers(companyId, {
      page: 1,
      itemsPerPage: PICK_SIZE,
      q: words,
      kind: null,
      isActive: true,
      order: null,
    });
    return page.rows.map((row) => ({ id: row.id, code: row.number, name: row.name }));
  }

  /** The customers the ids name, for a list already for one; one that cannot be read is left out. */
  async pickCustomerIds(companyId: string, ids: readonly string[]): Promise<PickOption[]> {
    const found: PickOption[] = [];
    for (const id of ids) {
      try {
        const row = await this.customers.customer(companyId, id);
        found.push({ id: row.id, code: row.number, name: row.name });
      } catch {
        // A customer it can no longer name is shown by its absence, never by failing the list's own screen.
      }
    }
    return found;
  }

  /** A write, then the lists read again, since a save moves a list's price count. */
  private async write(companyId: string, call: () => Promise<unknown>): Promise<boolean> {
    return this.run(async () => {
      await call();
      this.listsSignal.set(await this.api.list(companyId));
      return true;
    });
  }

  private async run(work: () => Promise<boolean>): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      return await work();
    } catch (error) {
      this.errorSignal.set(error instanceof PriceListsRefused ? error.code : 'network');
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }
}
