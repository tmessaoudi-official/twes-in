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
import { ProductScans } from '../products/product-scans';
import { type Scan, ScanBus, type ScanOutcome } from '../shared/scan/scan-bus';
import { addCount } from '../shared/scan/scan-lines';

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
  private readonly productScans = inject(ProductScans);

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
  /**
   * The movement form, for the product chosen: a tracked one also asks its lot (docs/SPEC.md § 7, 2026-09-23 slice 7).
   * Choosing it rebuilds the form over what was typed, as a location arriving does.
   */
  protected readonly descriptor = computed(() => {
    const operation = this.operation();
    const tracking = this.product()?.tracking ?? 'none';
    return operation === null ? null : movementForm(operation, this.facade.locations(), tracking);
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
    inject(ScanBus).handle((scan) => this.scanned(scan));
    // The picker shows what the form names: the form carries the id, and only this page knows how that id reads.
    effect((onCleanup) => {
      const form = this.form();
      if (form === null) return;
      const subscription = form.get('productId')?.valueChanges.subscribe((productId) => {
        const product = typeof productId === 'string' ? (this.known.get(productId) ?? null) : null;
        this.product.set(product);
        this.proposeHome(form, product);
      });
      onCleanup(() => subscription?.unsubscribe());
    });
  }

  /**
   * Where the chosen product normally lives, offered as the location (docs/SPEC.md row 101), so a person putting
   * goods away answers that question once rather than on every receipt.
   *
   * It is a proposal and behaves like one. It fills the box only while nobody has chosen a location themselves — a
   * touched control is left alone, because a screen that overwrites what a person typed teaches them to distrust it —
   * and a product without a single home, or one whose home this company no longer has, leaves whatever is there.
   *
   * A move is left out on purpose: its location is where the goods are now, which a home does not say, and proposing
   * the home as a destination would be refused whenever the goods are already there.
   */
  private proposeHome(form: DescriptorFormGroup, product: StockProductOption | null): void {
    if (this.operation() === 'move') return;
    const home = product?.homeLocationId ?? null;
    const control = form.get('locationId');
    if (home === null || control === null || control.dirty) return;
    if (!this.facade.locations().some((location) => location.id === home)) return;
    control.setValue(home);
    control.markAsPristine();
  }

  /**
   * A scan on an open movement fills it (docs/SPEC.md § 7, 2026-09-23 slice 7): the product, and from a GS1 label its
   * lot or serial number and the day it is used by, and the pieces the code enters — counting on, as a till does,
   * while the same lot is scanned again. With no movement open the card takes the scan. The product is asked by id,
   * exactly: a search answers a window of the catalogue and could miss it. Whether stock is kept of it is then the
   * API's to say when the movement is saved.
   */
  private async scanned(scan: Scan): Promise<ScanOutcome> {
    const companyId = this.company()?.id;
    const form = this.form();
    if (!companyId || form === null || !this.auth.hasPermission('product.read')) {
      return { kind: 'unclaimed' };
    }
    const named = await this.productScans.named(scan.code);
    if (named === null) return { kind: 'unclaimed' };
    if (!named.isActive) {
      return { kind: 'refused', key: 'scan.retired', params: { name: named.name } };
    }
    const [product] = await this.facade.pickProducts(companyId, { ids: [named.productId] });
    if (product === undefined) return { kind: 'unclaimed' };

    const before = form.getRawValue();
    const chosenBefore = this.product();
    const lotCode = named.lot ?? named.serial ?? '';
    const pieces = Math.max(named.quantity, 1) * scan.times;
    const same = before['productId'] === product.id && (before['lotCode'] ?? '') === lotCode;
    this.known.set(product.id, product);
    this.product.set(product);
    // Read again: a tracked product's form is another form, rebuilt over what was typed.
    const filled = this.form() ?? form;
    filled.patchValue({
      productId: product.id,
      lotCode,
      lotExpiresOn: named.useBy ?? (same ? (before['lotExpiresOn'] ?? '') : ''),
      quantity: same ? addCount(String(before['quantity'] ?? ''), pieces) : String(pieces),
    });
    this.proposeHome(filled, product);
    return {
      kind: 'done',
      key: 'inventory.scan.filled',
      params: { name: product.name },
      product: { name: named.name, unitPrice: named.unitPriceGross },
      undo: () => {
        this.product.set(chosenBefore);
        this.form()?.patchValue(before);
      },
    };
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
