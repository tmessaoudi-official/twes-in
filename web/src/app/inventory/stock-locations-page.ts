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
import type { ListDescriptor } from '../shared/list/list-types';
import { DataList, DataListCell } from '../shared/list/data-list';
import { PageTabs } from '../shared/ui/page-tabs';
import { StatusBadge } from '../shared/ui/status-badge';
import { InventoryFacade } from './inventory-facade';
import {
  LOCATIONS_LIST,
  locationForm,
  locationInput,
  locationListRows,
  locationValues,
  type StockLocationListRow,
} from './inventory-forms';
import { INVENTORY_TABS } from './inventory-nav';
import type { StockLocationRow } from './inventory-types';
import { Feedback } from '../shared/feedback/feedback';

/** Where stock is kept: each establishment's tree under its default location; a location goes only once empty. */
@Component({
  selector: 'app-stock-locations-page',
  imports: [
    PageTabs,
    MatButtonModule,
    MatCardModule,
    RouterLink,
    TranslatePipe,
    DataList,
    DataListCell,
    DescriptorForm,
    StatusBadge,
  ],
  templateUrl: './stock-locations-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class StockLocationsPage implements OnInit {
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly tabs = INVENTORY_TABS;
  private readonly facade = inject(InventoryFacade);
  private readonly feedback = inject(Feedback);
  private readonly auth = inject(AuthFacade);

  /**
   * Editing is what a person came for; deleting is destructive and sits behind "⋮", and the default location has
   * nothing to delete — the stock has to live somewhere — so the action is absent on it rather than refused.
   */
  protected readonly list = computed<ListDescriptor<StockLocationListRow>>(() => ({
    ...LOCATIONS_LIST,
    actions: [
      {
        id: 'edit',
        label: 'inventory.edit',
        icon: 'edit',
        run: (row) => this.open(row),
        disabled: () => this.busy(),
        shown: () => this.mayWrite(),
      },
      {
        id: 'delete',
        label: 'inventory.delete_named',
        labelParams: (row) => ({ name: row.path }),
        icon: 'delete',
        destructive: true,
        run: (row) => void this.remove(row),
        disabled: () => this.busy(),
        shown: (row) => this.mayWrite() && !row.isDefault,
        confirm: (row) => ({
          title: 'inventory.delete_title',
          message: 'inventory.delete_message',
          messageParams: { name: row.path },
          confirmLabel: 'inventory.delete_confirm',
          keepLabel: 'inventory.keep',
        }),
      },
    ],
  }));
  protected readonly rows = computed(() => locationListRows(this.facade.locations()));
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('stock.write'));
  protected readonly rowTestId = (row: StockLocationListRow): string =>
    `stock-location-${row.code}`;

  protected readonly editing = signal<StockLocationRow | 'new' | null>(null);
  protected readonly descriptor = computed(() => {
    const options = this.facade.options();
    const editing = this.editing();
    return options === null
      ? null
      : locationForm(
          options,
          this.facade.locations(),
          editing === null || editing === 'new' ? null : editing,
        );
  });
  /**
   * The form of the location being edited. Locations or options arriving while it is open change what is offered and
   * rebuild the form over what was typed, never over the location's own values; opening another starts from that one.
   */
  protected readonly form = linkedSignal<
    { editing: StockLocationRow | 'new' | null; descriptor: FormDescriptor | null },
    DescriptorFormGroup | null
  >({
    source: () => ({ editing: this.editing(), descriptor: this.descriptor() }),
    computation: ({ editing, descriptor }, previous) => {
      if (editing === null || descriptor === null) return null;
      const typed =
        previous?.value && previous.source.editing === editing
          ? previous.value.getRawValue()
          : null;
      return buildFormGroup(
        descriptor,
        typed ??
          untracked(() =>
            locationValues(
              editing === 'new' ? null : editing,
              this.facade.options() ?? { establishments: [], planShapes: [], structureShapes: [] },
            ),
          ),
      );
    },
  });

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      this.live.reloadOn(
        ['stock_location', 'stock', 'delivery_note'],
        () => this.facade.loadLocations(companyId),
        this.destroyRef,
      );
      await this.facade.loadLocations(companyId);
    }
  }

  protected open(target: StockLocationRow | 'new'): void {
    this.facade.clearError();
    this.editing.set(target);
  }

  protected cancel(): void {
    this.editing.set(null);
    this.facade.clearError();
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    const editing = this.editing();
    if (!companyId || editing === null || this.busy()) return;
    const input = locationInput(values);
    const accepted =
      editing === 'new'
        ? await this.facade.createLocation(companyId, input)
        : await this.facade.reviseLocation(companyId, editing.id, input);
    if (accepted) {
      this.editing.set(null);
      this.feedback.success('inventory.locations.saved');
    }
  }

  protected async remove(row: StockLocationRow): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) return;
    await this.facade.deleteLocation(companyId, row.id);
  }
}
