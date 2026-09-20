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
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { LiveChanges } from '../shared/realtime/live-changes';
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

/** How stock moved: one product's movements when the address names it, otherwise the company's, a page at a time. */
@Component({
  selector: 'app-stock-movements-page',
  imports: [
    PageTabs,
    MatButtonModule,
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

  protected readonly tabs = INVENTORY_TABS;
  private readonly facade = inject(InventoryFacade);
  private readonly auth = inject(AuthFacade);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);

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
      const companyId = untracked(this.company)?.id;
      const query = untracked(() => this.query);
      if (companyId && query !== null)
        void this.facade.loadMovements(companyId, this.search(query));
    });
    void this.start();
  }

  protected onQuery(query: ListQuery): void {
    this.query = query;
    const companyId = this.company()?.id;
    if (companyId) void this.facade.loadMovements(companyId, this.search(query));
  }

  /** The address names the product; the list names everything else. */
  private search(query: ListQuery) {
    return { ...movementSearch(query), productId: this.productId() ?? null };
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
