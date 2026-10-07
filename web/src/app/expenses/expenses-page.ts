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
import { firstValueFrom } from 'rxjs';
import { LiveChanges } from '../shared/realtime/live-changes';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { Feedback } from '../shared/feedback/feedback';
import { formatYearMonth, todayIn } from '../shared/i18n/format';
import { FormatFacade } from '../shared/i18n/format-facade';
import { AmountPipe, DayPipe } from '../shared/i18n/format-pipes';
import { DataList, DataListCell } from '../shared/list/data-list';
import type { ExportFormat } from '../shared/list/export-address';
import { ListExport } from '../shared/list/list-export';
import type { ListFacetCounts, ListPickSource, ListQuery } from '../shared/list/list-types';
import type { PickAsked } from '../shared/form/pick-api';
import { keyName } from '../shared/actions/shortcuts-sheet';
import { SettingsFacade } from '../shared/settings/settings-facade';
import { PRESENTATION } from '../shared/settings/settings-registry';
import { PageTabs } from '../shared/ui/page-tabs';
import { StatusBadge } from '../shared/ui/status-badge';
import { EXPENSES_LIST, expenseSearch, tejMonths } from './expense-forms';
import { TejFileDialog, type TejFileDialogData } from './tej-file-dialog';
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
    ListExport,
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
  private readonly dialog = inject(MatDialog);
  private readonly feedback = inject(Feedback);
  private readonly format = inject(FormatFacade);

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
  /** What each status chip would list, as the API counted it (docs/SPEC.md § 7, 2026-09-26). */
  protected readonly facetCounts = computed((): ListFacetCounts | null => {
    const counts = this.facade.statusCounts();
    return counts === null ? null : { status: { total: counts.all, options: counts.statuses } };
  });
  /**
   * Where the « Filtres » panel finds a vendor or a category to narrow by, and names the ones an address holds. Vendors
   * are searched by the API, never read whole; the categories are already here, for the form, so they are searched in
   * the browser. A category is named with the ones above it, since picking it lists what is filed under it too.
   */
  protected readonly pickSources: Readonly<Record<string, ListPickSource>> = {
    vendor: {
      search: (words) => this.pickVendors({ words }),
      byIds: (ids) => this.pickVendors({ ids }),
    },
    category: {
      search: async (words) => {
        const wanted = words.trim().toLocaleLowerCase();
        return this.categoryChoices().filter((each) =>
          each.name.toLocaleLowerCase().includes(wanted),
        );
      },
      byIds: async (ids) => this.categoryChoices().filter((each) => ids.includes(each.id)),
    },
  };
  private async pickVendors(asked: PickAsked) {
    const companyId = this.company()?.id;
    if (!companyId) return [];
    const found = await this.facade.pickVendors(companyId, asked);
    return found.map((vendor) => ({ id: vendor.id, code: vendor.number, name: vendor.name }));
  }
  private categoryChoices() {
    const categories = this.facade.options()?.categories ?? [];
    const byId = new Map(categories.map((category) => [category.id, category]));
    const path = (id: string | null, seen: ReadonlySet<string>): string[] => {
      const category = id === null || seen.has(id) ? undefined : byId.get(id);
      return category === undefined
        ? []
        : [...path(category.parentId, new Set([...seen, category.id])), category.name];
    };
    return categories.map((category) => ({
      id: category.id,
      code: '',
      name: path(category.id, new Set()).join(' › '),
    }));
  }
  /** The person's key for a new expense, named on the button (docs/SPEC.md § 7, 2026-09-24 22:51). */
  protected readonly newKey = computed(() => this.keys().new);
  protected readonly keyName = keyName;
  private readonly keys = inject(SettingsFacade).value(PRESENTATION.shortcuts);
  /** The API lists TEJ operations only for a company that declares to TEJ (Tunisia): the page decides nothing. */
  protected readonly declaresToTej = computed(
    () => (this.facade.options()?.withholdingOperationCodes.length ?? 0) > 0,
  );

  /** What the list last asked the API for; the page is not read until the list has said what it wants. */
  private search: ExpenseSearch | null = null;
  private readonly searched = signal<ExpenseSearch | null>(null);
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
      void this.facade.loadOptions(companyId);
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
    this.searched.set(this.search);
    void this.facade.loadPage(companyId, this.search);
    void this.facade.loadStatusCounts(companyId, this.search);
  }

  /** The month's TEJ file, asked in a dialog that also says what holds a month back. */
  protected async openTejFile(): Promise<void> {
    const company = this.company();
    if (!company) return;
    const locale = this.format.locale();
    const data: TejFileDialogData = {
      companyId: company.id,
      months: tejMonths(todayIn(company.timezone)).map((month) => ({
        value: month,
        label: formatYearMonth(month, locale),
      })),
    };
    const downloaded = await firstValueFrom(
      this.dialog.open(TejFileDialog, { data, autoFocus: 'first-tabbable' }).afterClosed(),
    );
    if (downloaded === true) this.feedback.success('expenses.tej_file.downloaded');
  }

  private async reload(companyId: string): Promise<void> {
    if (this.search !== null)
      await Promise.all([
        this.facade.loadPage(companyId, this.search),
        this.facade.loadStatusCounts(companyId, this.search),
      ]);
  }
}
