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
import type { PickOption } from '../shared/form/pick-field';
import { liveRecord } from '../shared/form/live-record';
import { RecordChanged } from '../shared/form/record-changed';
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
import {
  EXPENSE_STATUS_TONES,
  type ExpenseAttachment,
  type ExpenseVendorOption,
} from './expenses-types';
import { Feedback } from '../shared/feedback/feedback';

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
    RecordChanged,
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
  private readonly feedback = inject(Feedback);
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

  /** The saved version the form stands on, and what another person's save changed in it. */
  protected readonly sync = liveRecord({
    kind: 'expense',
    id: this.id,
    form: this.form,
    reload: async () => {
      const companyId = this.company()?.id;
      const id = this.id();
      if (companyId && id !== null) await this.facade.loadExpense(companyId, id);
    },
    saved: () => {
      const current = this.current();
      return current ? expenseValues(current, todayIn(this.company()?.timezone)) : null;
    },
  });
  /**
   * Which vendor the form names, as the picker answered it. The form carries the id; this is what the box READS,
   * which the form cannot know — an expense read from the API says it, and a search says it for a new one.
   */
  private readonly vendor = signal<ExpenseVendorOption | null>(null);
  protected readonly vendorShown = computed(() => {
    const vendor = this.vendor();
    return vendor === null ? null : { id: vendor.id, code: vendor.number, name: vendor.name };
  });
  /** Every vendor the picker has answered, so what the form names can be read back from its id. */
  private readonly known = new Map<string, ExpenseVendorOption>();
  protected readonly searchVendors = async (words: string): Promise<readonly PickOption[]> => {
    const companyId = this.company()?.id;
    if (!companyId) return [];
    const found = await this.facade.pickVendors(companyId, { words });
    for (const vendor of found) this.known.set(vendor.id, vendor);
    return found.map((vendor) => ({ id: vendor.id, code: vendor.number, name: vendor.name }));
  };

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
        this.confirmingDelete.set(false);
        if (companyId) {
          void this.facade.loadExpense(companyId, id);
        }
      });
    });
    // The picker shows what the form names, and choosing a vendor fills an empty category with that vendor's usual one.
    effect((onCleanup) => {
      const form = this.form();
      if (form === null) return;
      const subscription = form
        .get('vendorId')
        ?.valueChanges.pipe(takeUntilDestroyed(this.destroyRef))
        .subscribe((vendorId) => {
          const vendor = typeof vendorId === 'string' ? (this.known.get(vendorId) ?? null) : null;
          this.vendor.set(vendor);
          const category = form.get('categoryId');
          if (category && !category.value && vendor?.defaultExpenseCategoryId) {
            category.setValue(vendor.defaultExpenseCategoryId);
          }
        });
      onCleanup(() => subscription?.unsubscribe());
    });

    // An expense opened on an existing vendor is read back by id, retired or not: the book is never read whole.
    effect(() => {
      const companyId = this.company()?.id;
      const current = this.current();
      if (!companyId || current === undefined || current === null) return;
      const vendorId = current.vendorId;
      untracked(async () => {
        if (vendorId === null) {
          this.vendor.set(null);
          return;
        }
        if (this.vendor()?.id === vendorId) return;
        const known = this.known.get(vendorId);
        const [found] = known
          ? [known]
          : await this.facade.pickVendors(companyId, { ids: [vendorId] });
        if (found) this.known.set(found.id, found);
        this.vendor.set(found ?? null);
      });
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
    if (id === null) {
      const created = await this.facade.createExpense(companyId, input);
      if (created !== null) {
        this.feedback.success('expenses.saved');
        await this.router.navigate(['/expenses', created.id], { replaceUrl: true });
      }
    } else if ((await this.facade.reviseExpense(companyId, id, input)) !== null) {
      const form = this.form();
      if (form !== null) this.sync.savedHere(form);
      this.feedback.success('expenses.saved');
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
      this.feedback.success('expenses.deleted');
      await this.router.navigate(['/expenses'], { replaceUrl: true });
    }
  }

  protected async upload(event: Event): Promise<void> {
    const control = event.target as HTMLInputElement;
    const file = control.files?.item(0);
    const companyId = this.company()?.id;
    const id = this.id();
    if (!file || !companyId || id === null) return;
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
