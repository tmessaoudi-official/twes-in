// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  linkedSignal,
  OnInit,
  signal,
} from '@angular/core';
import { LiveChanges } from '../shared/realtime/live-changes';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { buildFormGroup, type DescriptorFormGroup } from '../shared/form/form-builder';
import type { FormDescriptor, FormValues } from '../shared/form/form-types';
import { DataList, DataListCell, DataListRowActions } from '../shared/list/data-list';
import { PageTabs } from '../shared/ui/page-tabs';
import { StatusBadge } from '../shared/ui/status-badge';
import {
  EXPENSE_CATEGORIES_LIST,
  categoryForm,
  categoryInput,
  categoryListRows,
  categoryValues,
  type ExpenseCategoryListRow,
} from './expense-forms';
import { ExpensesFacade } from './expenses-facade';
import { EXPENSES_TABS } from './expenses-nav';
import type { ExpenseCategoryRow } from './expenses-types';
import { Feedback } from '../shared/feedback/feedback';

/** The tree expenses are filed in: a category sits under another or at the top, and is deactivated, never deleted. */
@Component({
  selector: 'app-expense-categories-page',
  imports: [
    PageTabs,
    MatButtonModule,
    MatCardModule,
    TranslatePipe,
    DataList,
    DataListCell,
    DataListRowActions,
    DescriptorForm,
    StatusBadge,
  ],
  templateUrl: './expense-categories-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ExpenseCategoriesPage implements OnInit {
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly tabs = EXPENSES_TABS;
  private readonly facade = inject(ExpensesFacade);
  private readonly feedback = inject(Feedback);
  private readonly auth = inject(AuthFacade);

  protected readonly list = EXPENSE_CATEGORIES_LIST;
  protected readonly rows = computed(() => categoryListRows(this.facade.categories()));
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('expense.write'));
  protected readonly rowTestId = (row: ExpenseCategoryListRow): string =>
    `expense-category-${row.name}`;

  protected readonly editing = signal<ExpenseCategoryRow | 'new' | null>(null);
  protected readonly descriptor = computed(() => {
    const editing = this.editing();
    return categoryForm(
      this.facade.categories(),
      editing === null || editing === 'new' ? null : editing,
    );
  });
  /** Keeps what was typed while the categories are read again; opening another category starts from that one. */
  protected readonly form = linkedSignal<
    { editing: ExpenseCategoryRow | 'new' | null; descriptor: FormDescriptor },
    DescriptorFormGroup | null
  >({
    source: () => ({ editing: this.editing(), descriptor: this.descriptor() }),
    computation: ({ editing, descriptor }, previous) => {
      if (editing === null) return null;
      const typed =
        previous?.value && previous.source.editing === editing
          ? previous.value.getRawValue()
          : null;
      return buildFormGroup(
        descriptor,
        typed ?? categoryValues(editing === 'new' ? null : editing),
      );
    },
  });

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      this.live.reloadOn(
        ['expense_category', 'expense'],
        () => this.facade.loadCategories(companyId),
        this.destroyRef,
      );
      await this.facade.loadCategories(companyId);
    }
  }

  protected open(target: ExpenseCategoryRow | 'new'): void {
    this.facade.clearError();
    this.editing.set(target);
  }

  protected cancel(): void {
    this.editing.set(null);
    this.facade.clearError();
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    const editing = this.editing();
    if (!companyId || editing === null || this.busy()) return;
    const input = categoryInput(values);
    const accepted =
      editing === 'new'
        ? await this.facade.createCategory(companyId, input)
        : await this.facade.reviseCategory(companyId, editing.id, input);
    if (accepted) {
      this.editing.set(null);
      this.feedback.success('expenses.categories.saved');
    }
  }
}
