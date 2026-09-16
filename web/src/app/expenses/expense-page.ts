// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { buildFormGroup } from '../shared/form/form-builder';
import type { FormValues } from '../shared/form/form-types';
import { todayIn } from '../shared/i18n/format';
import { AmountPipe, DayPipe, MomentPipe } from '../shared/i18n/format-pipes';
import { StatusBadge } from '../shared/ui/status-badge';
import {
  expenseForm,
  expenseInput,
  expenseValues,
  paymentForm,
  paymentInput,
  paymentValues,
} from './expense-forms';
import { ExpensesFacade } from './expenses-facade';
import { EXPENSE_STATUS_TONES, type ExpenseAttachment } from './expenses-types';

/**
 * One expense: a draft to fill in, revise, attach receipts to and record; a recorded one to pay. A new expense takes
 * the chosen vendor's usual category while no category is picked.
 */
@Component({
  selector: 'app-expense-page',
  imports: [
    MatButtonModule,
    MatCardModule,
    RouterLink,
    TranslatePipe,
    DescriptorForm,
    StatusBadge,
    AmountPipe,
    DayPipe,
    MomentPipe,
  ],
  templateUrl: './expense-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ExpensePage {
  private readonly facade = inject(ExpensesFacade);
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);

  /** Bound from the route parameter by withComponentInputBinding(); absent on `expenses/new`. */
  readonly expenseId = input<string | undefined>(undefined);

  protected readonly tones = EXPENSE_STATUS_TONES;
  protected readonly id = computed(() => this.expenseId() ?? null);
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly attachments = this.facade.attachments;
  protected readonly options = this.facade.options;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('expense.write'));
  protected readonly saved = signal(false);
  protected readonly confirmingDelete = signal(false);

  /** Null while a new expense is filled in; undefined until the expense asked for has been read. */
  protected readonly current = computed(() => {
    const id = this.id();
    if (id === null) return null;
    const expense = this.facade.expense();
    return expense?.id === id ? expense : undefined;
  });
  protected readonly isDraft = computed(() => {
    const current = this.current();
    return current === null || current?.status === 'draft';
  });
  protected readonly descriptor = computed(() => {
    const options = this.facade.options();
    return options === null ? null : expenseForm(options, this.current() ?? null);
  });
  /** The form is rebuilt for another expense, other fields or a status change; never over what is being typed. */
  private readonly formKey = computed(() => {
    const descriptor = this.descriptor();
    const current = this.current();
    if (descriptor === null || current === undefined) return null;
    return `${current?.id ?? 'new'}|${current?.status ?? ''}|${JSON.stringify(descriptor)}`;
  });
  protected readonly form = computed(() => {
    if (this.formKey() === null) return null;
    return untracked(() => {
      const descriptor = this.descriptor();
      const current = this.current();
      if (descriptor === null || current === undefined) return null;
      // The company's day, not this browser's: the API takes a date up to the company's today and refuses a later
      // one, so someone whose own day has already turned would be proposed a value it answers 422 to.
      return buildFormGroup(descriptor, expenseValues(current, todayIn(this.company()?.timezone)));
    });
  });
  protected readonly paymentDescriptor = computed(() => {
    const options = this.facade.options();
    return options === null ? null : paymentForm(options);
  });
  protected readonly paymentFormGroup = computed(() => {
    const descriptor = this.paymentDescriptor();
    const id = this.current()?.id;
    return descriptor === null || id === undefined
      ? null
      : untracked(() =>
          buildFormGroup(descriptor, paymentValues(todayIn(this.company()?.timezone))),
        );
  });

  constructor() {
    effect(() => {
      const companyId = this.company()?.id;
      const id = this.id();
      untracked(() => {
        this.saved.set(false);
        this.confirmingDelete.set(false);
        if (companyId) {
          void this.facade.loadExpense(companyId, id);
        }
      });
    });
    // Choosing a vendor fills an empty category with the one that vendor's expenses usually go to.
    effect((onCleanup) => {
      const form = this.form();
      if (form === null) return;
      const subscription = form
        .get('vendorId')
        ?.valueChanges.pipe(takeUntilDestroyed(this.destroyRef))
        .subscribe((vendorId) => {
          const category = form.get('categoryId');
          const vendor = this.facade.options()?.vendors.find((row) => row.id === vendorId);
          if (category && !category.value && vendor?.defaultExpenseCategoryId) {
            category.setValue(vendor.defaultExpenseCategoryId);
          }
        });
      onCleanup(() => subscription?.unsubscribe());
    });
  }

  /** Whole kilobytes, rounded up: a receipt of a few bytes is not shown as nothing. */
  protected kilobytes(size: number): number {
    return Math.max(1, Math.ceil(size / 1024));
  }

  protected attachmentUrl(attachment: ExpenseAttachment): string {
    const companyId = this.company()?.id ?? '';
    return this.facade.attachmentUrl(companyId, this.id() ?? '', attachment.id);
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) return;
    const input = expenseInput(values);
    const id = this.id();
    this.saved.set(false);
    if (id === null) {
      const created = await this.facade.createExpense(companyId, input);
      if (created !== null) {
        await this.router.navigate(['/expenses', created.id], { replaceUrl: true });
      }
    } else if ((await this.facade.reviseExpense(companyId, id, input)) !== null) {
      this.saved.set(true);
    }
  }

  /** Saves what the form shows first, so what is recorded is what was read on screen. */
  protected async record(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    const form = this.form();
    if (!companyId || id === null || form === null || this.busy()) return;
    form.markAllAsTouched();
    if (form.invalid) return;
    this.saved.set(false);
    if (
      (await this.facade.reviseExpense(companyId, id, expenseInput(form.getRawValue()))) === null
    ) {
      return;
    }
    await this.facade.recordExpense(companyId, id);
  }

  protected async pay(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (!companyId || id === null || this.busy()) return;
    this.saved.set(false);
    await this.facade.payExpense(companyId, id, paymentInput(values));
  }

  protected async remove(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (!companyId || id === null || this.busy()) return;
    if (!this.confirmingDelete()) {
      this.confirmingDelete.set(true);
      return;
    }
    if (await this.facade.deleteExpense(companyId, id)) {
      await this.router.navigate(['/expenses'], { replaceUrl: true });
    }
  }

  protected async upload(event: Event): Promise<void> {
    const control = event.target as HTMLInputElement;
    const file = control.files?.item(0);
    const companyId = this.company()?.id;
    const id = this.id();
    if (!file || !companyId || id === null) return;
    this.saved.set(false);
    await this.facade.attach(companyId, id, file);
    control.value = '';
  }

  protected async detach(attachment: ExpenseAttachment): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (!companyId || id === null || this.busy()) return;
    await this.facade.detach(companyId, id, attachment.id);
  }
}
