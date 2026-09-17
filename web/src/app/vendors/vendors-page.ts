// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  OnInit,
} from '@angular/core';
import { LiveChanges } from '../shared/realtime/live-changes';
import { MatButtonModule } from '@angular/material/button';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DataList, DataListCell, DataListRowActions } from '../shared/list/data-list';
import { StatusBadge } from '../shared/ui/status-badge';
import { VENDORS_LIST } from './vendor-forms';
import { VendorsFacade } from './vendors-facade';
import type { VendorRow } from './vendors-types';

/** The vendors of the company being worked in. */
@Component({
  selector: 'app-vendors-page',
  imports: [
    MatButtonModule,
    RouterLink,
    TranslatePipe,
    DataList,
    DataListCell,
    DataListRowActions,
    StatusBadge,
  ],
  templateUrl: './vendors-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class VendorsPage implements OnInit {
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  private readonly facade = inject(VendorsFacade);
  private readonly auth = inject(AuthFacade);

  protected readonly list = VENDORS_LIST;
  protected readonly rows = this.facade.vendors;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('vendor.write'));
  protected readonly rowTestId = (row: VendorRow): string => `vendor-${row.number}`;

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      this.live.reloadOn(
        ['vendor', 'expense_category', 'custom_field'],
        () => this.facade.loadList(companyId),
        this.destroyRef,
      );
      await this.facade.loadList(companyId);
    }
  }
}
