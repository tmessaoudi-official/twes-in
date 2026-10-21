// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  OnInit,
  signal,
} from '@angular/core';
import type { ExportFormat } from '../shared/list/export-address';
import { ListExport } from '../shared/list/list-export';
import { LiveChanges } from '../shared/realtime/live-changes';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DataList, DataListCell, DataListSelection } from '../shared/list/data-list';
import { RecordDeletes } from '../shared/records/record-deletes';
import { StatusBadge } from '../shared/ui/status-badge';
import {
  type CustomerListRow,
  customerListRows,
  customerSearch,
  customersList,
} from './customer-forms';
import type { CustomerSearch } from './customers-types';
import type { ListDescriptor, ListQuery } from '../shared/list/list-types';
import { CustomersFacade } from './customers-facade';
import { PageTabs } from '../shared/ui/page-tabs';
import { CUSTOMERS_TABS } from './customers-nav';

/** The customers of the company being worked in, with a hidden column per custom field of theirs. */
@Component({
  selector: 'app-customers-page',
  imports: [
    PageTabs,
    MatButtonModule,
    MatIconModule,
    ListExport,
    RouterLink,
    TranslatePipe,
    DataList,
    DataListCell,
    DataListSelection,
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
  private readonly deletes = inject(RecordDeletes);

  // Customers are selected to be merged, which only a person who may remove them can do: a clerk adds customers, and a
  // duplicate it makes is merged by a manager.
  protected readonly list = computed<ListDescriptor<CustomerListRow>>(() => {
    const list = customersList(
      this.facade.customFields(),
      this.facade.groups(),
      this.facade.options()?.regimes ?? [],
    );
    return this.mayRemove() ? { ...list, selection: { countKey: 'customers.selected' } } : list;
  });
  /** The customers ticked on the page shown, by id, in the order they were ticked. */
  protected readonly selected = signal<readonly string[]>([]);
  protected readonly merging = signal(false);
  protected readonly rows = computed(() =>
    customerListRows(this.facade.customers(), this.facade.groups()),
  );
  protected readonly total = this.facade.total;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('customer.write'));
  protected readonly mayRemove = computed(() => this.auth.hasPermission('customer.remove'));
  protected readonly rowTestId = (row: CustomerListRow): string => `customer-${row.number}`;

  /** The page the list shows last asked for; a change elsewhere reads it again. */
  private search: CustomerSearch | null = null;
  private readonly searched = signal<CustomerSearch | null>(null);
  /** What the list shows now, as a file: null until the list has asked for its first page. */
  protected readonly exporter = computed(() => {
    const companyId = this.company()?.id;
    const search = this.searched();
    return companyId && search !== null
      ? (format: ExportFormat) => this.facade.exportUrl(companyId, search, format)
      : null;
  });

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      this.live.reloadOn(
        ['customer', 'customer_group', 'custom_field'],
        () => this.reload(companyId),
        this.destroyRef,
      );
      await this.facade.loadListContext(companyId);
    }
  }

  /**
   * Merges the customers ticked into the one the person keeps; the others stay, switched off and pointing at it, for
   * what was sent or issued to them, their contacts and defaults going to it. The dialog first says, from the API, what
   * moves and what stays.
   */
  protected async merge(): Promise<void> {
    const companyId = this.company()?.id;
    const ids = this.selected();
    if (!companyId || ids.length < 2 || this.merging()) return;
    this.merging.set(true);
    try {
      if (
        await this.deletes.offerMergeOf(companyId, 'customer', ids, {
          settings: true,
          aliased: true,
        })
      ) {
        this.selected.set([]);
      }
    } finally {
      this.merging.set(false);
    }
  }

  protected onQuery(query: ListQuery): void {
    const companyId = this.company()?.id;
    if (!companyId) return;
    this.search = customerSearch(query);
    this.searched.set(this.search);
    void this.facade.loadPage(companyId, this.search);
  }

  private async reload(companyId: string): Promise<void> {
    await Promise.all([
      this.facade.loadListContext(companyId),
      this.search === null ? Promise.resolve() : this.facade.loadPage(companyId, this.search),
    ]);
  }
}
