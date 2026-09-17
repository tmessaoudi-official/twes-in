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
import { buildFormGroup, type DescriptorFormGroup } from '../shared/form/form-builder';
import type { FormDescriptor, FormValues } from '../shared/form/form-types';
import { AmountPipe } from '../shared/i18n/format-pipes';
import { DataList, DataListCell, DataListRowActions } from '../shared/list/data-list';
import { PageTabs } from '../shared/ui/page-tabs';
import { StatusBadge } from '../shared/ui/status-badge';
import { InventoryFacade } from './inventory-facade';
import {
  movementForm,
  movementInput,
  movementValues,
  STOCK_LIST,
  type StockListRow,
  stockListRows,
} from './inventory-forms';
import { INVENTORY_TABS } from './inventory-nav';
import type { StockOperation } from './inventory-types';
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
    stockListRows(this.facade.levels(), this.facade.options(), this.facade.locations()),
  );
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('stock.write'));
  protected readonly rowTestId = (row: StockListRow): string =>
    `stock-${row.productReference}-${row.locationCode}`;

  protected readonly operation = signal<StockOperation | null>(null);
  protected readonly descriptor = computed(() => {
    const operation = this.operation();
    const options = this.facade.options();
    return operation === null || options === null
      ? null
      : movementForm(operation, options, this.facade.locations());
  });
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
        typed ?? untracked(() => movementValues(this.facade.locations(), this.facade.options())),
      );
    },
  });

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      this.live.reloadOn(
        ['stock', 'delivery_note', 'product', 'stock_location'],
        () => this.facade.loadStock(companyId),
        this.destroyRef,
      );
      await this.facade.loadStock(companyId);
    }
  }

  protected open(operation: StockOperation): void {
    this.facade.clearError();
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
