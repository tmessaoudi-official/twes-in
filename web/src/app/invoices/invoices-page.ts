// SPDX-License-Identifier: AGPL-3.0-or-later

import { withdrawn } from '../shared/theme/lifecycle-tones';
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
import { todayIn } from '../shared/i18n/format';
import { AmountPipe, DayPipe } from '../shared/i18n/format-pipes';
import { DataList, DataListCell } from '../shared/list/data-list';
import type { ListQuery } from '../shared/list/list-types';
import type { StatusTone } from '../shared/theme/accent-theme';
import { StatusBadge } from '../shared/ui/status-badge';
import {
  INVOICES_LIST,
  type InvoiceListRow,
  invoiceListRows,
  invoiceSearch,
} from './invoice-forms';
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
  ],
  templateUrl: './invoices-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class InvoicesPage implements OnInit {
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  private readonly facade = inject(InvoicesFacade);
  private readonly auth = inject(AuthFacade);

  protected readonly list = INVOICES_LIST;
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

  /** What the list last asked the API for; the page is not read until the list has said what it wants. */
  private search: InvoiceSearch | null = null;

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
  }

  private async reload(companyId: string): Promise<void> {
    await Promise.all([
      this.facade.loadListContext(companyId),
      this.search === null ? Promise.resolve() : this.facade.loadPage(companyId, this.search),
    ]);
  }
}
