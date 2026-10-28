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
  viewChild,
} from '@angular/core';
import { LiveChanges } from '../shared/realtime/live-changes';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatDialog } from '@angular/material/dialog';
import { RouterLink } from '@angular/router';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { RefusalLine } from '../shared/documents/refusal-line';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { RefusalFigures } from '../shared/form/refusal-figures';
import { editorActions } from '../shared/form/editor-actions';
import { RecordBar } from '../shared/form/record-bar';
import {
  buildFormGroup,
  valuesAsTyped,
  type DescriptorFormGroup,
} from '../shared/form/form-builder';
import { listRecord } from '../shared/form/list-record';
import { RecordChanged } from '../shared/form/record-changed';
import type { FormDescriptor, FormValues } from '../shared/form/form-types';
import type { ListDescriptor } from '../shared/list/list-types';
import { DataList, DataListCell } from '../shared/list/data-list';
import { PageTabs } from '../shared/ui/page-tabs';
import { StatusBadge } from '../shared/ui/status-badge';
import { InventoryFacade } from './inventory-facade';
import {
  locationsList,
  locationForm,
  locationInput,
  locationListRows,
  locationValues,
  type StockLocationListRow,
} from './inventory-forms';
import { INVENTORY_TABS } from './inventory-nav';
import type { StockLocationRow } from './inventory-types';
import { Feedback } from '../shared/feedback/feedback';
import { PlaceContentsDialog, type PlaceContentsDialogData } from './place-contents-dialog';
import { TextsParts } from '../shared/i18n/texts-part';
import { STOCK_COUNT_TEXTS } from './stock-count-texts';

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
    RecordBar,
    RecordChanged,
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
  private readonly dialog = inject(MatDialog);
  private readonly texts = inject(TextsParts);

  /**
   * Reading what a place holds is open to anyone who reads stock, the map or not; editing is what a writer came for;
   * deleting is destructive and sits behind "⋮", and the default location has nothing to delete — the stock has to
   * live somewhere — so the action is absent on it rather than refused.
   */
  protected readonly list = computed<ListDescriptor<StockLocationListRow>>(() => ({
    ...locationsList(this.facade.options()?.establishments ?? []),
    actions: [
      {
        id: 'contents',
        label: 'inventory.locations.contents',
        labelParams: (row) => ({ name: row.path }),
        icon: 'inventory_2',
        run: (row) => void this.showContents(row),
      },
      {
        // Count mode at that place, the address its printed label carries: walking it with a scanner.
        id: 'count_here',
        label: 'inventory.locations.count_here',
        labelParams: (row) => ({ name: row.path }),
        icon: 'checklist',
        link: (row) => ['/stock/locations', row.id],
        rare: true,
        shown: () => this.mayWrite(),
      },
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
          kind: 'definitif',
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
  /** The line above the form: what no field took, or the error's own words. */
  protected readonly refusalLine = new RefusalLine(
    this.facade.refusals,
    'inventory.errors.',
    inject(TranslateService),
    inject(RefusalFigures),
  );
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
          ? valuesAsTyped(previous.value)
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

  /** The saved version the open form stands on, and what another person's save changed in it. */
  protected readonly sync = listRecord({
    kind: 'stock_location',
    editing: this.editing,
    rows: this.facade.locations,
    form: this.form,
    reload: async () => {
      const companyId = this.company()?.id;
      if (companyId) await this.facade.loadLocations(companyId);
    },
    values: (row) =>
      locationValues(
        row,
        this.facade.options() ?? { establishments: [], planShapes: [], structureShapes: [] },
      ),
  });
  private readonly editor = viewChild(DescriptorForm);
  /** The open editor's bar: what is unsaved, its close and its save, which goes through the form's own submit. */
  protected readonly editorActions = editorActions({
    changes: this.sync.changes,
    busy: this.busy,
    save: () => this.editor()?.submit(),
    close: () => this.cancel(),
    closeLabel: 'inventory.cancel',
    saveLabel: 'inventory.save',
  });

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      this.live.reloadOn(
        ['stock_location', 'stock', 'delivery_note', 'invoice'],
        () => this.facade.loadLocations(companyId),
        this.destroyRef,
      );
      await this.facade.loadLocations(companyId);
    }
  }

  protected async showContents(row: StockLocationListRow): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId) return;
    // A count asked from a line says what it changes, in texts this route does not open by itself.
    await this.texts.open(STOCK_COUNT_TEXTS);
    this.dialog.open<PlaceContentsDialog, PlaceContentsDialogData>(PlaceContentsDialog, {
      data: { companyId, locationId: row.id, path: row.path },
      width: '32rem',
      maxWidth: '100vw',
    });
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
        : await this.facade.reviseLocation(companyId, editing.id, input, this.sync.version());
    if (accepted) {
      this.editing.set(null);
      this.feedback.success('inventory.locations.saved');
    } else if (this.error() === 'record_changed') {
      this.facade.clearError();
      await this.sync.savedElsewhere();
    } else {
      // Each field the API refused said on that field; the line above the form says what none of them took.
      const refused = this.facade.refusals();
      this.refusalLine.placed(refused, this.editor()?.showRefused(refused) ?? refused);
    }
  }

  protected async remove(row: StockLocationRow): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) return;
    await this.facade.deleteLocation(companyId, row.id);
  }
}
