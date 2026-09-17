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
import { type CustomerListRow, customerListRows, customersList } from './customer-forms';
import { CustomersFacade } from './customers-facade';
import { PageTabs } from '../shared/ui/page-tabs';
import { CUSTOMERS_TABS } from './customers-nav';

/** The customers of the company being worked in, with a hidden column per custom field of theirs. */
@Component({
  selector: 'app-customers-page',
  imports: [
    PageTabs,
    MatButtonModule,
    RouterLink,
    TranslatePipe,
    DataList,
    DataListCell,
    DataListRowActions,
    StatusBadge,
  ],
  templateUrl: './customers-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CustomersPage implements OnInit {
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly tabs = CUSTOMERS_TABS;
  private readonly facade = inject(CustomersFacade);
  private readonly auth = inject(AuthFacade);

  protected readonly list = computed(() => customersList(this.facade.customFields()));
  protected readonly rows = computed(() =>
    customerListRows(this.facade.customers(), this.facade.groups()),
  );
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('customer.write'));
  protected readonly rowTestId = (row: CustomerListRow): string => `customer-${row.number}`;

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      this.live.reloadOn(
        ['customer', 'customer_group', 'custom_field'],
        () => this.facade.loadList(companyId),
        this.destroyRef,
      );
      await this.facade.loadList(companyId);
    }
  }
}
