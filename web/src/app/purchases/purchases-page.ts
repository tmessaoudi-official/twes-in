// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  OnInit,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { keyName } from '../shared/actions/shortcuts-sheet';
import type { PickAsked } from '../shared/form/pick-api';
import { AmountPipe, DayPipe } from '../shared/i18n/format-pipes';
import { DataList, DataListCell } from '../shared/list/data-list';
import type { ListFacetCounts, ListPickSource, ListQuery } from '../shared/list/list-types';
import { LiveChanges } from '../shared/realtime/live-changes';
import { SettingsFacade } from '../shared/settings/settings-facade';
import { PRESENTATION } from '../shared/settings/settings-registry';
import type { StatusTone } from '../shared/theme/accent-theme';
import { withdrawn } from '../shared/theme/lifecycle-tones';
import { StatusBadge } from '../shared/ui/status-badge';
import { PURCHASES_LIST, purchaseOrderSearch } from './purchase-order-forms';
import { PurchasesFacade } from './purchases-facade';
import {
  PURCHASE_ORDER_STATUS_STAGES,
  PURCHASE_ORDER_STATUS_TONES,
  type PurchaseOrderSearch,
  type PurchaseOrderStatus,
} from './purchases-types';

/** The orders the company being worked in sent, or is drafting, to its vendors. */
@Component({
  selector: 'app-purchases-page',
  imports: [
    MatButtonModule,
    RouterLink,
    TranslatePipe,
    AmountPipe,
    DayPipe,
    DataList,
    DataListCell,
    StatusBadge,
  ],
  templateUrl: './purchases-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PurchasesPage implements OnInit {
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly facade = inject(PurchasesFacade);
  private readonly auth = inject(AuthFacade);

  protected readonly list = PURCHASES_LIST;
  /** A list cell's row is untyped, so the tone is looked up through a typed function. */
  protected readonly struckOf = (status: PurchaseOrderStatus): boolean =>
    withdrawn(PURCHASE_ORDER_STATUS_STAGES[status]);
  protected readonly toneOf = (status: PurchaseOrderStatus): StatusTone =>
    PURCHASE_ORDER_STATUS_TONES[status];
  protected readonly rows = this.facade.orders;
  protected readonly total = this.facade.total;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('purchase.write'));
  protected readonly rowTestId = (row: { id: string }): string => `purchase-order-${row.id}`;
  /** What each status chip would list, as the API counted it. */
  protected readonly facetCounts = computed((): ListFacetCounts | null => {
    const counts = this.facade.statusCounts();
    return counts === null ? null : { status: { total: counts.all, options: counts.statuses } };
  });
  /** Where the « Filtres » panel finds a vendor to narrow by, and names the ones an address holds. */
  protected readonly pickSources: Readonly<Record<string, ListPickSource>> = {
    vendor: {
      search: (words) => this.pickVendors({ words }),
      byIds: (ids) => this.pickVendors({ ids }),
    },
  };
  /** The person's key for a new order, named on the button. */
  protected readonly newKey = computed(() => this.keys().new);
  protected readonly keyName = keyName;
  private readonly keys = inject(SettingsFacade).value(PRESENTATION.shortcuts);

  /** What the list last asked the API for; the page is not read until the list has said what it wants. */
  private search: PurchaseOrderSearch | null = null;

  ngOnInit(): void {
    const companyId = this.company()?.id;
    if (companyId) {
      // A receipt is recorded on its order, and a vendor renamed shows on its drafts.
      this.live.reloadOn(
        ['purchase_order', 'vendor'],
        () => this.reload(companyId),
        this.destroyRef,
      );
    }
  }

  protected onQuery(query: ListQuery): void {
    const companyId = this.company()?.id;
    if (!companyId) return;
    this.search = purchaseOrderSearch(query);
    void this.facade.loadPage(companyId, this.search);
    void this.facade.loadStatusCounts(companyId, this.search);
  }

  private async pickVendors(asked: PickAsked) {
    const companyId = this.company()?.id;
    if (!companyId) return [];
    const found = await this.facade.pickVendors(companyId, asked);
    return found.map((vendor) => ({ id: vendor.id, code: vendor.number, name: vendor.name }));
  }

  private async reload(companyId: string): Promise<void> {
    if (this.search === null) return;
    await Promise.all([
      this.facade.loadPage(companyId, this.search),
      this.facade.loadStatusCounts(companyId, this.search),
    ]);
  }
}
