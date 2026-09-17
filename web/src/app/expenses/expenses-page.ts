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
import { AmountPipe, DayPipe } from '../shared/i18n/format-pipes';
import { DataList, DataListCell, DataListRowActions } from '../shared/list/data-list';
import type { ListQuery } from '../shared/list/list-types';
import { PageTabs } from '../shared/ui/page-tabs';
import { StatusBadge } from '../shared/ui/status-badge';
import { EXPENSES_LIST, expenseSearch } from './expense-forms';
import { ExpensesFacade } from './expenses-facade';
import { EXPENSES_TABS } from './expenses-nav';
import type { StatusTone } from '../shared/theme/accent-theme';
import {
  EXPENSE_STATUS_TONES,
  type ExpenseRow,
  type ExpenseSearch,
  type ExpenseStatus,
} from './expenses-types';

/** The expenses of the company being worked in, the latest first. */
@Component({
  selector: 'app-expenses-page',
  imports: [
    PageTabs,
    MatButtonModule,
    RouterLink,
    TranslatePipe,
    AmountPipe,
    DayPipe,
    DataList,
    DataListCell,
    DataListRowActions,
    StatusBadge,
  ],
  templateUrl: './expenses-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ExpensesPage implements OnInit {
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  private readonly facade = inject(ExpensesFacade);
  private readonly auth = inject(AuthFacade);

  protected readonly tabs = EXPENSES_TABS;
  /** A list cell's row is untyped, so the tone is looked up through a typed function. */
  protected readonly toneOf = (status: ExpenseStatus): StatusTone => EXPENSE_STATUS_TONES[status];
  protected readonly list = EXPENSES_LIST;
  protected readonly rows = this.facade.expenses;
  protected readonly total = this.facade.total;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('expense.write'));
  protected readonly rowTestId = (row: ExpenseRow): string => `expense-${row.id}`;

  /** What the list last asked the API for; the page is not read until the list has said what it wants. */
  private search: ExpenseSearch | null = null;

  ngOnInit(): void {
    const companyId = this.company()?.id;
    if (companyId) {
      this.live.reloadOn(
        ['expense', 'vendor', 'expense_category', 'custom_field'],
        () => this.reload(companyId),
        this.destroyRef,
      );
    }
  }

  protected onQuery(query: ListQuery): void {
    const companyId = this.company()?.id;
    if (!companyId) return;
    this.search = expenseSearch(query);
    void this.facade.loadPage(companyId, this.search);
  }

  private async reload(companyId: string): Promise<void> {
    if (this.search !== null) await this.facade.loadPage(companyId, this.search);
  }
}
