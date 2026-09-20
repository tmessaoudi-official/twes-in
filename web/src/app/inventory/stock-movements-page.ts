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
import { PageTabs } from '../shared/ui/page-tabs';
import { InventoryFacade } from './inventory-facade';
import { MOVEMENTS_LIST, movementListRows, type StockMovementListRow } from './inventory-forms';
import { INVENTORY_TABS } from './inventory-nav';

/** How stock moved: one product's movements when the address names it, otherwise the company's latest. */
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

  protected readonly list = MOVEMENTS_LIST;
  protected readonly rows = computed(() =>
    movementListRows(this.facade.movements(), this.facade.locations()),
  );
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly rowTestId = (row: StockMovementListRow): string => `stock-movement-${row.id}`;

  constructor() {
    // The page stays when only the query changes, as when leaving one product for all of them.
    effect(() => {
      const productId = this.productId() ?? null;
      const companyId = untracked(this.company)?.id;
      if (companyId) {
        void this.facade.loadMovements(companyId, productId);
      }
    });
    inject(LiveChanges).reloadOn(
      ['stock', 'delivery_note'],
      async () => {
        const companyId = this.company()?.id;
        if (companyId) await this.facade.loadMovements(companyId, this.productId() ?? null);
      },
      inject(DestroyRef),
    );
  }
}
