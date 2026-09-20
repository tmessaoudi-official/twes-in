// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  inject,
  linkedSignal,
  OnInit,
  signal,
  untracked,
} from '@angular/core';
import { LiveChanges } from '../shared/realtime/live-changes';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DescriptorForm } from '../shared/form/descriptor-form';
import type { PickOption } from '../shared/form/pick-field';
import { buildFormGroup, type DescriptorFormGroup } from '../shared/form/form-builder';
import type { FormDescriptor, FormValues } from '../shared/form/form-types';
import { AmountPipe } from '../shared/i18n/format-pipes';
import { DataList, DataListCell } from '../shared/list/data-list';
import { PageTabs } from '../shared/ui/page-tabs';
import { StatusBadge } from '../shared/ui/status-badge';
import { InventoryFacade } from './inventory-facade';
import type { ListDescriptor, ListQuery } from '../shared/list/list-types';
import {
  movementForm,
  movementInput,
  movementValues,
  STOCK_LIST,
  type StockListRow,
  stockListRows,
  stockSearch,
} from './inventory-forms';
import { INVENTORY_TABS } from './inventory-nav';
import type { StockOperation, StockProductOption, StockSearch } from './inventory-types';
import { Feedback } from '../shared/feedback/feedback';

/** What is on hand of each product whose stock is kept, per location, with goods received and counts recorded here. */
@Component({
  selector: 'app-stock-page',
  imports: [
    PageTabs,
    MatButtonModule,
    MatCardModule,
    RouterLink,
    TranslatePipe,
    AmountPipe,
    DataList,
    DataListCell,
    DescriptorForm,
    StatusBadge,
  ],
  templateUrl: './stock-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class StockPage implements OnInit {
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly tabs = INVENTORY_TABS;
  private readonly facade = inject(InventoryFacade);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);

  /** Where a line's quantity came from: an address, so it is a real link rather than a button that navigates. */
  protected readonly list = computed<ListDescriptor<StockListRow>>(() => ({
    ...STOCK_LIST,
    actions: [
      {
        id: 'movements',
        label: 'inventory.stock.movements_of',
        labelParams: (row) => ({ name: row.productName }),
        icon: 'swap_vert',
        link: () => ['/stock/movements'],
        linkQuery: (row) => ({ productId: row.productId }),
      },
    ],
  }));
  protected readonly rows = computed(() =>
    stockListRows(this.facade.levels(), this.facade.locations()),
  );
  protected readonly total = this.facade.total;
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('stock.write'));
  protected readonly rowTestId = (row: StockListRow): string =>
    `stock-${row.productReference}-${row.locationCode}`;

  protected readonly operation = signal<StockOperation | null>(null);
  protected readonly descriptor = computed(() => {
    const operation = this.operation();
    return operation === null ? null : movementForm(operation, this.facade.locations());
  });

  /**
   * Which product the form names, as the picker answered it — the catalogue is never held here. The form owns the
   * id; this is what the box READS, which the form cannot know, and what it must read again after a rebuild.
   */
  private readonly product = signal<StockProductOption | null>(null);
  protected readonly productShown = computed(() => {
    const product = this.product();
    return product === null
      ? null
      : { id: product.id, code: product.reference, name: product.name };
  });
  /** Every product the picker has answered, so what is chosen can be found again from the id in the form. */
  private readonly known = new Map<string, StockProductOption>();
  protected readonly searchProducts = async (words: string): Promise<readonly PickOption[]> => {
    const companyId = this.company()?.id;
    if (!companyId) return [];
    const found = await this.facade.pickProducts(companyId, { words });
    for (const product of found) this.known.set(product.id, product);
    return found.map((product) => ({
      id: product.id,
      code: product.reference,
      name: product.name,
    }));
  };

  /**
   * The form of the movement being recorded. Options or locations arriving while it is open rebuild it over what was
   * typed; switching between a receipt and a count starts afresh.
   */
  protected readonly form = linkedSignal<
    { operation: StockOperation | null; descriptor: FormDescriptor | null },
    DescriptorFormGroup | null
  >({
    source: () => ({ operation: this.operation(), descriptor: this.descriptor() }),
    computation: ({ operation, descriptor }, previous) => {
      if (operation === null || descriptor === null) return null;
      const typed =
        previous?.value && previous.source.operation === operation
          ? previous.value.getRawValue()
          : null;
      return buildFormGroup(
        descriptor,
        typed ?? untracked(() => movementValues(this.facade.locations())),
      );
    },
  });

  constructor() {
    // The picker shows what the form names: the form carries the id, and only this page knows how that id reads.
    effect((onCleanup) => {
      const form = this.form();
      if (form === null) return;
      const subscription = form.get('productId')?.valueChanges.subscribe((productId) => {
        this.product.set(
          typeof productId === 'string' ? (this.known.get(productId) ?? null) : null,
        );
      });
      onCleanup(() => subscription?.unsubscribe());
    });
  }

  /** What the list last asked the API for; the page is not read until the list has said what it wants. */
  private search: StockSearch | null = null;

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      this.live.reloadOn(
        ['stock', 'delivery_note', 'product', 'stock_location'],
        () => this.reload(companyId),
        this.destroyRef,
      );
      await this.facade.loadStockContext(companyId);
    }
  }

  protected onQuery(query: ListQuery): void {
    const companyId = this.company()?.id;
    if (!companyId) return;
    this.search = stockSearch(query);
    void this.facade.loadStock(companyId, this.search);
  }

  private async reload(companyId: string): Promise<void> {
    await Promise.all([
      this.facade.loadStockContext(companyId),
      this.search === null ? Promise.resolve() : this.facade.loadStock(companyId, this.search),
    ]);
  }

  protected open(operation: StockOperation): void {
    this.facade.clearError();
    this.product.set(null);
    this.operation.set(operation);
  }

  protected cancel(): void {
    this.operation.set(null);
    this.facade.clearError();
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    const operation = this.operation();
    if (!companyId || operation === null || this.busy()) return;
    if (await this.facade.record(companyId, movementInput(operation, values))) {
      this.operation.set(null);
      this.feedback.success('inventory.stock.recorded');
    }
  }
}
