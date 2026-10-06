// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  inject,
  untracked,
} from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { AmountPipe } from '../shared/i18n/format-pipes';
import { LiveChanges } from '../shared/realtime/live-changes';
import { PageTabs } from '../shared/ui/page-tabs';
import { InventoryFacade } from './inventory-facade';
import { INVENTORY_TABS } from './inventory-nav';

/**
 * What the stock is worth: each product at the weighted average of what came in, and the total. Stock whose cost is not
 * known is counted apart and adds nothing, so the total is never a guess. Only someone who may read what things cost
 * sees it.
 */
@Component({
  selector: 'app-stock-valuation-page',
  imports: [PageTabs, TranslatePipe, AmountPipe],
  templateUrl: './stock-valuation-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class StockValuationPage {
  protected readonly tabs = INVENTORY_TABS;
  private readonly facade = inject(InventoryFacade);
  private readonly auth = inject(AuthFacade);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly valuation = this.facade.valuation;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayRead = computed(() => this.auth.hasPermission('product.cost.read'));
  /** Whether some stock has no known cost, which the total leaves out. */
  protected readonly hasUnvalued = computed(
    () => this.valuation()?.lines.some((line) => Number(line.unvaluedQuantity) !== 0) ?? false,
  );

  constructor() {
    effect(() => {
      const companyId = this.company()?.id;
      if (companyId && this.mayRead()) untracked(() => void this.facade.loadValuation(companyId));
    });
    // A receipt, a sale or a cost changed by a teammate changes what the stock is worth.
    this.live.reloadOn(
      ['stock', 'delivery_note', 'invoice', 'product'],
      async () => {
        const companyId = this.company()?.id;
        if (companyId && this.mayRead()) await this.facade.loadValuation(companyId);
      },
      this.destroyRef,
    );
  }
}
