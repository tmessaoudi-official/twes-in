// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
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
import { PickField, type PickOption } from '../shared/form/pick-field';
import { buildFormGroup, type DescriptorFormGroup } from '../shared/form/form-builder';
import type { FormDescriptor, FormValues } from '../shared/form/form-types';
import { AmountPipe } from '../shared/i18n/format-pipes';
import { DataList, DataListCell, DataListRowActions } from '../shared/list/data-list';
import { PageTabs } from '../shared/ui/page-tabs';
import { StatusBadge } from '../shared/ui/status-badge';
import { InventoryFacade } from './inventory-facade';
import type { ListQuery } from '../shared/list/list-types';
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
    DataListRowActions,
    DescriptorForm,
    PickField,
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

  protected readonly list = STOCK_LIST;
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

  /** Which product the movement is of, as the picker answered it; the catalogue is never held here. */
  protected readonly product = signal<StockProductOption | null>(null);
  protected readonly productShown = computed(() => {
    const product = this.product();
    return product === null
      ? null
      : { id: product.id, code: product.reference, name: product.name };
  });
  /** Every product the picker has answered, so what is chosen can be found again from the option's id. */
  private readonly known = new Map<string, StockProductOption>();
  /** Set when saving was asked for with no product named, since it is not a field the form can mark. */
  protected readonly productMissing = signal(false);
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

  protected chooseProduct(option: PickOption | null): void {
    const product = option === null ? null : (this.known.get(option.id) ?? null);
    this.product.set(product);
    this.productMissing.set(product === null);
  }
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
    this.productMissing.set(false);
    this.operation.set(operation);
  }

  protected cancel(): void {
    this.operation.set(null);
    this.facade.clearError();
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    const operation = this.operation();
    const productId = this.product()?.id ?? '';
    this.productMissing.set(productId === '');
    if (!companyId || operation === null || productId === '' || this.busy()) return;
    if (await this.facade.record(companyId, movementInput(operation, values, productId))) {
      this.operation.set(null);
      this.feedback.success('inventory.stock.recorded');
    }
  }
}
