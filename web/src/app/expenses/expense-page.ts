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
  viewChild,
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
  billGroup,
  billInput,
  billTaxName,
  expenseForm,
  expenseInput,
  expenseValues,
  classifyForm,
  paymentForm,
  paymentInput,
  paymentValues,
} from './expense-forms';
import { ExpensesFacade } from './expenses-facade';
import {
  EXPENSE_STATUS_TONES,
  type ExpenseAttachment,
  type ExpenseInput,
  type ExpenseVendorOption,
} from './expenses-types';
import { ExpenseBill } from './expense-bill';
import { PartConflict } from '../shared/form/part-conflict';
import { Feedback } from '../shared/feedback/feedback';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { revertToSaved, unsavedChanges } from '../shared/form/dirty-count';
import { ScreenActions } from '../shared/actions/screen-actions';
import type { ScreenAction } from '../shared/actions/screen-action';
import { RecordBar } from '../shared/form/record-bar';
import { FileDrop } from '../shared/form/file-drop';
import { ATTACHMENT_MAX_BYTES } from '../shared/form/file-limits';

/**
 * One expense: a draft to fill in, revise, attach receipts to and record; a recorded one to pay. A new expense takes
 * the chosen vendor's usual category while no category is picked.
 */
@Component({
  selector: 'app-expense-page',
  imports: [
    ExpenseBill,
    PartConflict,
    FileDrop,
    MatButtonModule,
    MatCardModule,
    RouterLink,
    TranslatePipe,
    DescriptorForm,
    RecordBar,
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
  protected readonly maxBytes = ATTACHMENT_MAX_BYTES;
  private readonly facade = inject(ExpensesFacade);
  private readonly unsaved = inject(UnsavedChanges);
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
    return options === null
      ? null
      : expenseForm(options, this.current() ?? null, this.auth.hasModule('vendors'));
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

  protected readonly isBillConflict = (conflict: { field: string }): boolean =>
    conflict.field === 'bill';
  /** Bumped to show the saved bill again in place of the one shown. */
  private readonly billVersion = signal(0);
  /** The supplier's bill, edited beside the form and rebuilt with it; null until the expense has been read. */
  protected readonly bill = computed(() => {
    if (this.formKey() === null) return null;
    this.billVersion();
    return untracked(() => {
      const options = this.options();
      const current = this.current();
      if (options === null || current === undefined) return null;
      return billGroup(current, options);
    });
  });
  /** The bill as it is saved, as the editor's values; a new expense's is the empty line it starts on. */
  private readonly savedBill = computed(() => {
    const options = this.options();
    const current = this.current();
    return options === null || current === undefined
      ? null
      : billGroup(current, options).getRawValue();
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
    // The bill is one field: a line is never merged with another person's.
    parts: [
      {
        field: 'bill',
        saved: () => {
          const saved = this.savedBill();
          return saved === null ? null : JSON.stringify(saved);
        },
        shown: () => JSON.stringify(this.bill()?.getRawValue() ?? null),
        take: () => this.billVersion.update((version) => version + 1),
      },
    ],
  });

  /** What the form holds that the API has not been told yet; the bar beside the title shows it. */
  protected readonly savedValues = computed(() => {
    const current = this.current();
    return current ? expenseValues(current, todayIn(this.company()?.timezone)) : null;
  });
  private readonly headerChanges = unsavedChanges(this.form, this.savedValues);
  private readonly billChanges = unsavedChanges(this.bill, this.savedBill);
  protected readonly changes = computed(() => this.headerChanges() + this.billChanges());
  /** Each tax the saved bill charges, named; the figures list them under the totals. */
  protected readonly taxRows = computed(() => {
    const options = this.options();
    return (this.current()?.taxes ?? []).map((tax) => ({
      ...tax,
      name: options === null ? tax.code : billTaxName(options, tax),
    }));
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
          buildFormGroup(
            descriptor,
            paymentValues(
              todayIn(this.company()?.timezone),
              this.current()?.suggestedWithholdingRate ?? null,
            ),
          ),
        );
  });

  /**
   * The TEJ operation of a paid expense (docs/SPEC.md § 7, 2026-09-26 00:22): what was said, labelled as the
   * administration publishes it, or '' for nobody said. Null for a company that does not declare to TEJ.
   */
  protected readonly tejOperation = computed(
    () => {
      const operations = this.options()?.withholdingOperationCodes ?? [];
      const current = this.current();
      if (operations.length === 0 || current?.status !== 'paid') return null;
      const code = current.withholdingOperationCode;
      const found = operations.find((operation) => operation.code === code);
      return {
        code: code ?? '',
        label: found === undefined ? null : `${found.code} — ${found.label}`,
      };
    },
    // A reload that answers the same operation must not rebuild the form over what is being chosen.
    { equal: (a, b) => a?.code === b?.code && a?.label === b?.label },
  );
  protected readonly classifyDescriptor = computed(() => {
    const options = this.options();
    return options === null || !this.mayWrite() || this.tejOperation() === null
      ? null
      : classifyForm(options);
  });
  protected readonly classifyFormGroup = computed(() => {
    const descriptor = this.classifyDescriptor();
    const code = this.tejOperation()?.code ?? '';
    return descriptor === null
      ? null
      : untracked(() => buildFormGroup(descriptor, { withholdingOperationCode: code }));
  });

  constructor() {
    // The same list the bar draws also answers the keyboard, the palette and the "?" sheet (row 45).
    inject(ScreenActions).declare(this.recordActions);
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

  /**
   * What this page offers, declared once (row 45): the bar beside the title draws it, and the keyboard, the Ctrl K
   * palette and the "?" sheet read the same list — so "s" saves here as it does on a document.
   */
  protected readonly recordActions = computed<ScreenAction[]>(() => {
    const busy = this.busy();
    const changes = this.changes();
    const may = this.mayWrite() && this.isDraft();
    return [
      {
        id: 'save',
        label: 'form.save',
        icon: 'save',
        primary: true,
        shortcut: 's',
        // A save that is always available teaches nothing about whether there is anything to save.
        disabled: busy || changes === 0,
        run: () => this.saveFromBar(),
        shown: may,
      },
      {
        id: 'revert',
        label: 'form.revert',
        disabled: busy,
        run: () => this.revert(),
        shown: may && changes > 0,
      },
    ];
  });

  /** The record's own form, which shows what a save from the bar refused and puts the person on it. */
  private readonly mainForm = viewChild<DescriptorForm>('mainForm');
  /** The bill beside it, which says a line's refusal under that line. */
  private readonly billView = viewChild(ExpenseBill);
  /** The payment's form, which says what the payment was refused on. */
  private readonly paymentForm = viewChild<DescriptorForm>('paymentForm');

  /** From the bar beside the title, which holds no form of its own. */
  protected saveFromBar(): void {
    const form = this.form();
    if (form === null) return;
    if (form.invalid) {
      const shown = this.mainForm();
      if (shown) shown.showErrors();
      else form.markAllAsTouched();
      this.bill()?.markAllAsTouched();
      return;
    }
    void this.save(form.getRawValue());
  }

  protected revert(): void {
    const form = this.form();
    const saved = this.savedValues();
    if (form !== null && saved !== null) revertToSaved(form, saved);
    this.billVersion.update((version) => version + 1);
  }

  /** The expense as the API takes it: the form's fields and the bill; null while the bill is not as it may be sent. */
  private inputOf(values: FormValues): ExpenseInput | null {
    const bill = this.bill();
    const options = this.options();
    if (bill === null || options === null) return null;
    if (bill.invalid) {
      bill.markAllAsTouched();
      return null;
    }
    const current = this.current() ?? null;
    return {
      ...expenseInput(values, current?.vendorId ?? null),
      ...billInput(bill.getRawValue(), options, current),
    };
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) return;
    const input = this.inputOf(values);
    if (input === null) return;
    const id = this.id();
    if (id === null) {
      const created = await this.facade.createExpense(companyId, input);
      if (created !== null) {
        this.feedback.success('expenses.saved');
        // It exists now: going to it is not leaving unsaved work, though the form still holds what was
        // typed and the record holds what the API answered (row 45's leave guard, 2026-09-20).
        this.unsaved.savedAndLeaving();
        await this.router.navigate(['/expenses', created.id], { replaceUrl: true });
      } else {
        this.showRefused();
      }
    } else if ((await this.facade.reviseExpense(companyId, id, input)) !== null) {
      const form = this.form();
      if (form !== null) this.sync.savedHere(form);
      this.feedback.success('expenses.saved');
    } else {
      this.showRefused();
    }
  }

  /**
   * Each field the API refused said on that field, a line's under that line of the bill, and the person put on the
   * first; the line above the form stays for what names no field.
   */
  private showRefused(): void {
    const refused = this.facade.refusals();
    if (refused.length === 0) return;
    const form = this.mainForm();
    const left = form === undefined ? refused : form.showRefused(refused);
    this.billView()?.showRefused(left, left.length === refused.length);
  }

  /** Saves what the form shows first, so what is recorded is what was read on screen. */
  protected async record(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    const form = this.form();
    if (!companyId || id === null || form === null || this.busy()) return;
    form.markAllAsTouched();
    if (form.invalid) return;
    const input = this.inputOf(form.getRawValue());
    if (input === null) return;
    if (
      (await this.facade.reviseExpense(companyId, id, input)) === null ||
      (await this.facade.recordExpense(companyId, id)) === null
    ) {
      this.showRefused();
    }
  }

  protected async pay(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (!companyId || id === null || this.busy()) return;
    if ((await this.facade.payExpense(companyId, id, paymentInput(values))) === null) {
      // What names no field of the payment stays on the line above the form.
      this.paymentForm()?.showRefused(this.facade.refusals());
    }
  }

  protected async classify(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    const code = String(values['withholdingOperationCode'] ?? '');
    if (!companyId || id === null || code === '' || this.busy()) return;
    if ((await this.facade.classifyWithholding(companyId, id, code)) !== null) {
      this.feedback.success('expenses.tej.classified');
    }
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
      // The record is GONE: asking whether to keep unsaved changes to it would be asking about something that no
      // longer exists, and the only answer that saves anything is the one nobody can take (2026-09-20).
      this.unsaved.savedAndLeaving();
      await this.router.navigate(['/expenses'], { replaceUrl: true });
    }
  }

  protected async upload(file: File): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (!companyId || id === null) return;
    await this.facade.attach(companyId, id, file);
  }

  protected async detach(attachment: ExpenseAttachment): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (!companyId || id === null || this.busy()) return;
    if (await this.facade.detach(companyId, id, attachment.id)) {
      this.feedback.success(
        'expenses.attachments.removed',
        { name: attachment.name },
        {
          key: 'expenses.attachments.undo',
          run: () => void this.restore(companyId, id, attachment),
        },
      );
    }
  }

  /** A refusal, such as the expense having been recorded or filled up since, is said as a toast: the action was one. */
  private async restore(
    companyId: string,
    id: string,
    attachment: ExpenseAttachment,
  ): Promise<void> {
    const refused = await this.facade.restoreAttachment(companyId, id, attachment.id);
    if (refused === null) {
      this.feedback.success('expenses.attachments.restored', { name: attachment.name });
    } else {
      this.feedback.failure(`expenses.errors.${refused}`);
    }
  }
}
