// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  inject,
  input,
  untracked,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { ProductScans } from '../products/product-scans';
import { LiveChanges } from '../shared/realtime/live-changes';
import { type Scan, ScanBus, type ScanOutcome } from '../shared/scan/scan-bus';
import { AmountPipe, MomentPipe } from '../shared/i18n/format-pipes';
import { DataList, DataListCell } from '../shared/list/data-list';
import type { ListQuery } from '../shared/list/list-types';
import { PageTabs } from '../shared/ui/page-tabs';
import { InventoryFacade } from './inventory-facade';
import {
  MOVEMENTS_LIST,
  movementListRows,
  movementSearch,
  type StockMovementListRow,
} from './inventory-forms';
import { INVENTORY_TABS } from './inventory-nav';

/**
 * How stock moved: one product's movements when the address names it, one lot's or serial number's when it names that
 * (the recall search, row 63 slice 10: typed, or read from a scanned GS1 label), otherwise the company's, a page at a
 * time.
 */
@Component({
  selector: 'app-stock-movements-page',
  imports: [
    PageTabs,
    MatButtonModule,
    MatFormFieldModule,
    MatInputModule,
    RouterLink,
    TranslatePipe,
    AmountPipe,
    MomentPipe,
    DataList,
    DataListCell,
  ],
  templateUrl: './stock-movements-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class StockMovementsPage {
  /** The `productId` query parameter, bound by the router. */
  readonly productId = input<string | undefined>();
  /** The `lot` query parameter, bound by the router: a lot or serial code, matched whole. */
  readonly lot = input<string | undefined>();

  protected readonly tabs = INVENTORY_TABS;
  private readonly facade = inject(InventoryFacade);
  private readonly auth = inject(AuthFacade);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  private readonly router = inject(Router);
  private readonly productScans = inject(ProductScans);

  protected readonly list = MOVEMENTS_LIST;
  protected readonly rows = computed(() =>
    movementListRows(this.facade.movements(), this.facade.locations()),
  );
  protected readonly total = this.facade.movementsTotal;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly rowTestId = (row: StockMovementListRow): string => `stock-movement-${row.id}`;

  /** What the list last asked the API for; the page is not read until the list has said what it wants. */
  private query: ListQuery | null = null;

  constructor() {
    // The page stays when only the query changes, as when leaving one product for all of them, so the product it
    // narrows to is read again here rather than only when the list first speaks.
    effect(() => {
      this.productId();
      this.lot();
      const companyId = untracked(this.company)?.id;
      const query = untracked(() => this.query);
      if (companyId && query !== null)
        void this.facade.loadMovements(companyId, this.search(query));
    });
    inject(ScanBus).handle((scan) => this.scanned(scan));
    void this.start();
  }

  /** Opens the movements of the lot or serial number typed into the search. */
  protected findLot(code: string): void {
    const lot = code.trim();
    if (lot !== '') void this.router.navigate(['/stock/movements'], { queryParams: { lot } });
  }

  protected onQuery(query: ListQuery): void {
    this.query = query;
    const companyId = this.company()?.id;
    if (companyId) void this.facade.loadMovements(companyId, this.search(query));
  }

  /** The address names the product or the lot; the list names everything else. */
  private search(query: ListQuery) {
    return {
      ...movementSearch(query),
      productId: this.productId() ?? null,
      lot: this.lot() ?? null,
    };
  }

  /**
   * A label carrying a lot or serial number opens its movements; any other code is the card's, as on every screen
   * that has nothing to do with it.
   */
  private async scanned(scan: Scan): Promise<ScanOutcome> {
    if (!this.company() || !this.auth.hasPermission('product.read')) return { kind: 'unclaimed' };
    const named = await this.productScans.named(scan.code);
    const lot = named?.lot ?? named?.serial ?? null;
    if (named === null || lot === null) return { kind: 'unclaimed' };
    await this.router.navigate(['/stock/movements'], { queryParams: { lot } });
    return { kind: 'done', key: 'inventory.movements.scanned_lot', params: { code: lot } };
  }

  private async start(): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId) return;
    this.live.reloadOn(
      ['stock', 'delivery_note'],
      () => this.facade.reloadMovements(companyId),
      this.destroyRef,
    );
    await this.facade.loadLocations(companyId);
  }
}
