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
import { LiveChanges } from '../shared/realtime/live-changes';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DataList, DataListCell, DataListSelection } from '../shared/list/data-list';
import type { ExportFormat } from '../shared/list/export-address';
import { ListExport } from '../shared/list/list-export';
import { StatusBadge } from '../shared/ui/status-badge';
import type { ListDescriptor, ListQuery } from '../shared/list/list-types';
import { RecordDeletes } from '../shared/records/record-deletes';
import { VENDORS_LIST, vendorSearch } from './vendor-forms';
import { VendorsFacade } from './vendors-facade';
import type { VendorRow, VendorSearch } from './vendors-types';

/** The vendors of the company being worked in. */
@Component({
  selector: 'app-vendors-page',
  imports: [
    MatButtonModule,
    MatIconModule,
    RouterLink,
    TranslatePipe,
    DataList,
    DataListCell,
    DataListSelection,
    ListExport,
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

  private readonly deletes = inject(RecordDeletes);

  // Vendors are selected to be merged, which only a person who may change them can do.
  protected readonly list = computed<ListDescriptor<VendorRow>>(() =>
    this.mayWrite()
      ? { ...VENDORS_LIST, selection: { countKey: 'vendors.selected' } }
      : VENDORS_LIST,
  );
  /** The vendors ticked on the page shown, by id, in the order they were ticked. */
  protected readonly selected = signal<readonly string[]>([]);
  protected readonly merging = signal(false);
  protected readonly rows = this.facade.vendors;
  protected readonly total = this.facade.total;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('vendor.write'));
  protected readonly rowTestId = (row: VendorRow): string => `vendor-${row.number}`;

  /** The page the list shows last asked for; a change elsewhere reads it again. */
  private search: VendorSearch | null = null;
  private readonly searched = signal<VendorSearch | null>(null);
  /** What the list shows now, as a file: null until the list has asked for its first page. */
  protected readonly exporter = computed(() => {
    const companyId = this.company()?.id;
    const search = this.searched();
    return companyId && search !== null
      ? (format: ExportFormat) => this.facade.exportUrl(companyId, search, format)
      : null;
  });

  ngOnInit(): void {
    const companyId = this.company()?.id;
    if (companyId) {
      this.live.reloadOn(
        ['vendor', 'expense_category', 'custom_field'],
        async () => {
          if (this.search !== null) await this.facade.loadPage(companyId, this.search);
        },
        this.destroyRef,
      );
    }
  }

  /**
   * Merges the vendors ticked into the one the person keeps; the others stay, switched off and pointing at it, for
   * what already names them. The dialog first says, from the API, what moves and what stays.
   */
  protected async merge(): Promise<void> {
    const companyId = this.company()?.id;
    const ids = this.selected();
    if (!companyId || ids.length < 2 || this.merging()) return;
    this.merging.set(true);
    try {
      if (
        await this.deletes.offerMergeOf(companyId, 'vendor', ids, {
          settings: false,
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
    this.search = vendorSearch(query);
    this.searched.set(this.search);
    void this.facade.loadPage(companyId, this.search);
  }
}
