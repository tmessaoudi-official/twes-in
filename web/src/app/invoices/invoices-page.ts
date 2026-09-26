// SPDX-License-Identifier: AGPL-3.0-or-later

import { withdrawn } from '../shared/theme/lifecycle-tones';
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  inject,
  input,
  OnInit,
  untracked,
} from '@angular/core';
import { LiveChanges } from '../shared/realtime/live-changes';
import { MatButtonModule } from '@angular/material/button';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { todayIn } from '../shared/i18n/format';
import { AmountPipe, DayPipe } from '../shared/i18n/format-pipes';
import { DataList, DataListCell } from '../shared/list/data-list';
import type { ListDescriptor, ListFacetCounts, ListQuery } from '../shared/list/list-types';
import { keyName } from '../shared/actions/shortcuts-sheet';
import { SettingsFacade } from '../shared/settings/settings-facade';
import { PRESENTATION } from '../shared/settings/settings-registry';
import type { StatusTone } from '../shared/theme/accent-theme';
import { StatusBadge } from '../shared/ui/status-badge';
import { WINDOW_CLASS } from '../shared/ui/window-class';
import {
  INVOICES_LIST,
  type InvoiceListRow,
  invoiceListRows,
  invoiceSearch,
} from './invoice-forms';
import { InvoiceSheet } from './invoice-sheet';
import { InvoicesFacade } from './invoices-facade';
import {
  INVOICE_STATUS_TONES,
  INVOICE_STATUS_STAGES,
  type InvoiceSearch,
  type InvoiceShownStatus,
} from './invoices-types';

/** The invoices and credit notes of the company being worked in, with what each still has due. */
@Component({
  selector: 'app-invoices-page',
  imports: [
    MatButtonModule,
    RouterLink,
    TranslatePipe,
    AmountPipe,
    DayPipe,
    DataList,
    DataListCell,
    StatusBadge,
    InvoiceSheet,
  ],
  templateUrl: './invoices-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class InvoicesPage implements OnInit {
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  private readonly facade = inject(InvoicesFacade);
  private readonly auth = inject(AuthFacade);

  private readonly router = inject(Router);
  private readonly windowClass = inject(WINDOW_CLASS);

  /** `?open=` — the document whose sheet is over the list (docs/SPEC.md § 7, 2026-09-26); bound from the address. */
  readonly open = input<string | undefined>(undefined);

  /**
   * From a tablet up an issued document opens as a sheet over the list (row 123), so its link names it in the list's
   * own address; a draft opens its editor, and on a phone every document opens at its own address, since a sheet
   * there would cover the whole list.
   */
  protected readonly list = computed((): ListDescriptor<InvoiceListRow> => {
    if (this.windowClass() === 'compact') return INVOICES_LIST;
    const sheet = (row: InvoiceListRow) => row.status !== 'draft';
    return {
      ...INVOICES_LIST,
      link: (row) => (sheet(row) ? ['/invoices'] : ['/invoices', row.id]),
      linkQuery: (row) => (sheet(row) ? { open: row.id } : null),
    };
  });
  /** The sheet shown, never on a phone. */
  protected readonly sheetId = computed(() =>
    this.windowClass() === 'compact' ? null : (this.open() ?? null),
  );
  protected readonly today = computed(() => todayIn(this.company()?.timezone));
  protected readonly wide = computed(() => this.windowClass() === 'expanded');
  /** A list cell's row is untyped, so the tone is looked up through a typed function. */
  protected readonly struckOf = (status: InvoiceShownStatus): boolean =>
    withdrawn(INVOICE_STATUS_STAGES[status]);
  protected readonly toneOf = (status: InvoiceShownStatus): StatusTone =>
    INVOICE_STATUS_TONES[status];
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly rows = computed(() =>
    invoiceListRows(this.facade.invoices(), todayIn(this.company()?.timezone)),
  );
  protected readonly scale = computed(() => this.facade.options()?.currencyScale ?? null);
  protected readonly total = this.facade.total;
  protected readonly error = this.facade.error;
  protected readonly mayWrite = computed(() => this.auth.hasPermission('invoice.write'));
  protected readonly rowTestId = (row: InvoiceListRow): string => `invoice-${row.id}`;
  /** What each status chip would list, as the API counted it (docs/SPEC.md § 7, 2026-09-26). */
  protected readonly facetCounts = computed((): ListFacetCounts | null => {
    const counts = this.facade.statusCounts();
    return counts === null ? null : { status: { total: counts.all, options: counts.statuses } };
  });
  /** The person's key for a new document, named on the button (docs/SPEC.md § 7, 2026-09-24 22:51). */
  protected readonly newKey = computed(() => this.keys().new);
  protected readonly keyName = keyName;
  private readonly keys = inject(SettingsFacade).value(PRESENTATION.shortcuts);

  /** What the list last asked the API for; the page is not read until the list has said what it wants. */
  private search: InvoiceSearch | null = null;

  constructor() {
    // A sheet named on a phone — a link copied from a wider screen — opens the document at its own address instead.
    effect(() => {
      const open = this.open();
      if (open === undefined || this.windowClass() !== 'compact') return;
      untracked(() => void this.router.navigate(['/invoices', open], { replaceUrl: true }));
    });
  }

  protected closeSheet(): void {
    void this.router.navigate([], { queryParams: { open: null }, queryParamsHandling: 'merge' });
  }

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      this.live.reloadOn(
        ['invoice', 'customer', 'delivery_note'],
        () => this.reload(companyId),
        this.destroyRef,
      );
      await this.facade.loadListContext(companyId);
    }
  }

  protected onQuery(query: ListQuery): void {
    const companyId = this.company()?.id;
    if (!companyId) return;
    this.search = invoiceSearch(query);
    void this.facade.loadPage(companyId, this.search);
    void this.facade.loadStatusCounts(companyId, this.search);
  }

  private async reload(companyId: string): Promise<void> {
    await Promise.all([
      this.facade.loadListContext(companyId),
      this.search === null ? Promise.resolve() : this.facade.loadPage(companyId, this.search),
      this.search === null
        ? Promise.resolve()
        : this.facade.loadStatusCounts(companyId, this.search),
    ]);
  }
}
