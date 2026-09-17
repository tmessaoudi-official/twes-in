// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { buildFormGroup } from '../shared/form/form-builder';
import { todayIn } from '../shared/i18n/format';
import { AmountPipe, DayPipe } from '../shared/i18n/format-pipes';
import { StatusBadge } from '../shared/ui/status-badge';
import {
  defaultDocumentTaxes,
  documentTaxOptions,
  invoiceForm,
  invoiceInput,
  invoiceValues,
  linesArray,
  paymentForm,
  paymentInput,
  paymentValues,
  shownStatus,
} from './invoice-forms';
import { InvoiceLines } from './invoice-lines';
import { liveRecord } from '../shared/form/live-record';
import { PartConflict } from '../shared/form/part-conflict';
import { RecordChanged } from '../shared/form/record-changed';
import { InvoicesFacade } from './invoices-facade';
import { INVOICE_STATUS_TONES, type InvoiceInput, type Payment } from './invoices-types';
import { Feedback } from '../shared/feedback/feedback';

/**
 * One invoice or credit note: a new draft to fill in, a draft to revise, issue or cancel, or an issued document to
 * print, pay and correct with a credit note. Only a draft changes; the API refuses anything else whatever this
 * screen shows, and works out every figure it shows.
 */
@Component({
  selector: 'app-invoice-page',
  imports: [
    MatButtonModule,
    MatCardModule,
    MatCheckboxModule,
    RouterLink,
    TranslatePipe,
    AmountPipe,
    DayPipe,
    DescriptorForm,
    InvoiceLines,
    PartConflict,
    RecordChanged,
    StatusBadge,
  ],
  templateUrl: './invoice-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class InvoicePage {
  private readonly facade = inject(InvoicesFacade);
  private readonly feedback = inject(Feedback);
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);

  /** Bound from the route parameter by withComponentInputBinding(); absent on `invoices/new`. */
  readonly invoiceId = input<string | undefined>(undefined);

  protected readonly id = computed(() => this.invoiceId() ?? null);
  protected readonly options = this.facade.options;
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly scale = computed(() => this.options()?.currencyScale ?? null);
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly today = computed(() => todayIn(this.company()?.timezone));
  protected readonly mayWrite = computed(() => this.auth.hasPermission('invoice.write'));
  protected readonly mayIssue = computed(() => this.auth.hasPermission('invoice.issue'));
  protected readonly mayPay = computed(() => this.auth.hasPermission('payment.write'));
  protected readonly confirmingCancel = signal(false);
  protected readonly confirmingPaymentDelete = signal<string | null>(null);

  /** Null while a new invoice is filled in; undefined until the document asked for has been read. */
  protected readonly current = computed(() => {
    const id = this.id();
    if (id === null) return null;
    const invoice = this.facade.invoice();
    return invoice?.id === id ? invoice : undefined;
  });
  protected readonly isCreditNote = computed(() => this.current()?.type === 'credit_note');
  protected readonly shown = computed(() => {
    const current = this.current();
    return current ? shownStatus(current, this.today()) : null;
  });
  protected readonly tones = INVOICE_STATUS_TONES;
  protected readonly editable = computed(() => {
    const current = this.current();
    return this.mayWrite() && (current === null || current?.status === 'draft');
  });
  /** An invoice needs a customer: a company without one is told so rather than shown a form it cannot fill. */
  protected readonly noCustomers = computed(
    () => this.current() === null && this.options()?.customers.length === 0,
  );
  protected readonly descriptor = computed(() => {
    const options = this.options();
    const current = this.current();
    if (options === null || current === undefined || this.noCustomers()) return null;
    return invoiceForm(options, current);
  });
  /**
   * What the form is of: the document, its state and the fields shown. Reading the document again yields new objects
   * with the same content, and must not rebuild the form over what is being typed; another document or state must.
   */
  private readonly formKey = computed(() => {
    const descriptor = this.descriptor();
    const current = this.current();
    if (descriptor === null || current === undefined) return null;
    return `${current?.id ?? 'new'}|${current?.status ?? 'draft'}|${JSON.stringify(descriptor)}`;
  });
  protected readonly form = computed(() => {
    if (this.formKey() === null) return null;
    return untracked(() => {
      const descriptor = this.descriptor();
      const options = this.options();
      const current = this.current();
      if (descriptor === null || options === null || current === undefined) return null;
      return buildFormGroup(descriptor, invoiceValues(current, options));
    });
  });
  protected readonly isLinesConflict = (conflict: { field: string }): boolean =>
    conflict.field === 'lines';
  /** Bumped to show the saved lines again in place of the ones shown. */
  private readonly linesVersion = signal(0);
  protected readonly lines = computed(() => {
    if (this.formKey() === null) return null;
    this.linesVersion();
    return untracked(() => {
      const options = this.options();
      const current = this.current();
      if (options === null || current === undefined) return null;
      const customer = options.customers.find((each) => each.id === current?.customerId) ?? null;
      return linesArray(current?.lines ?? [], options, customer);
    });
  });
  /** The saved version the document stands on, and what another person's save changed in it. */
  protected readonly sync = liveRecord({
    kind: 'invoice',
    id: this.id,
    form: this.form,
    reload: async () => {
      const companyId = this.company()?.id;
      const id = this.id();
      if (companyId && id !== null) await this.facade.loadInvoice(companyId, id);
    },
    saved: () => {
      const current = this.current();
      const options = this.options();
      return current && options ? invoiceValues(current, options) : null;
    },
    // The lines and the document taxes are one field: a line is never merged with another person's.
    parts: [
      {
        field: 'lines',
        saved: () => {
          const current = this.current();
          const options = this.options();
          if (!current || options === null) return null;
          const customer = options.customers.find((each) => each.id === current.customerId) ?? null;
          return this.linesText(
            linesArray(current.lines, options, customer).getRawValue(),
            this.savedDocumentTaxes(),
          );
        },
        shown: () => this.linesText(this.lines()?.getRawValue() ?? [], this.documentTaxes()),
        take: () => {
          this.linesVersion.update((version) => version + 1);
          this.documentTaxes.set(this.savedDocumentTaxes());
        },
      },
    ],
  });
  protected readonly nets = computed(() => (this.current()?.lines ?? []).map((line) => line.net));

  private readonly customerId = signal('');
  protected readonly customer = computed(
    () => this.options()?.customers.find((each) => each.id === this.customerId()) ?? null,
  );
  /** The fixed charges and withholdings chosen for the document, prefilled with what the API would charge. */
  protected readonly documentTaxes = signal<readonly string[]>([]);
  protected readonly documentTaxChoices = computed(() => {
    const options = this.options();
    return options === null
      ? []
      : documentTaxOptions(options, this.customerId(), this.documentTaxes());
  });

  protected readonly payment = computed(() => {
    const current = this.current();
    if (current === undefined || current === null || !this.canRecordPayment()) return null;
    return untracked(() => ({
      descriptor: paymentForm(),
      group: buildFormGroup(paymentForm(), paymentValues(this.today(), current.amountDue)),
    }));
  });

  protected readonly pdfUrl = computed(() => {
    const companyId = this.company()?.id;
    const current = this.current();
    return companyId && current ? this.facade.pdfUrl(companyId, current.id) : null;
  });
  protected readonly canIssue = computed(
    () => this.current()?.status === 'draft' && this.mayWrite() && this.mayIssue(),
  );
  protected readonly canCancel = computed(
    () => this.current()?.status === 'draft' && this.mayWrite(),
  );
  protected readonly isOpen = computed(() => {
    const status = this.current()?.status;
    return status === 'issued' || status === 'partially_paid' || status === 'paid';
  });
  /** A credit note takes off at most what is still due, so an invoice with nothing due offers none. */
  protected readonly canCredit = computed(
    () =>
      !this.isCreditNote() &&
      this.isOpen() &&
      this.mayWrite() &&
      !this.isZero(this.current()?.amountDue ?? '0'),
  );
  protected readonly showsPayments = computed(() => !this.isCreditNote() && this.isOpen());
  protected readonly canRecordPayment = computed(() => {
    const current = this.current();
    const status = current?.status;
    return (
      !this.isCreditNote() &&
      (status === 'issued' || status === 'partially_paid') &&
      this.mayPay() &&
      !this.isZero(current?.amountDue ?? '0')
    );
  });

  constructor() {
    effect(() => {
      const companyId = this.company()?.id;
      const id = this.id();
      untracked(() => {
        this.confirmingCancel.set(false);
        this.confirmingPaymentDelete.set(null);
        if (companyId) {
          void this.facade.loadInvoice(companyId, id);
        }
      });
    });
    effect(() => {
      const lines = this.lines();
      const editable = this.editable();
      if (lines === null) return;
      untracked(() => (editable ? lines.enable() : lines.disable()));
    });
    // The customer drives the line taxes offered and the document taxes; choosing another resets the latter.
    effect((onCleanup) => {
      const control = this.form()?.controls['customerId'];
      if (control === undefined) return;
      untracked(() => {
        const options = this.options();
        const customerId = String(control.value ?? '');
        this.customerId.set(customerId);
        const chosen = this.current()?.documentTaxComponentIds;
        this.documentTaxes.set(
          chosen ?? (options === null ? [] : defaultDocumentTaxes(options, customerId)),
        );
      });
      const subscription = control.valueChanges.subscribe((value) => {
        const customerId = String(value ?? '');
        this.customerId.set(customerId);
        const options = this.options();
        this.documentTaxes.set(options === null ? [] : defaultDocumentTaxes(options, customerId));
        this.applyCustomerDiscount();
      });
      onCleanup(() => subscription.unsubscribe());
    });
  }

  /** Lines whose discount nobody typed take the customer's default, as a new line of theirs would. */
  private applyCustomerDiscount(): void {
    const rate = this.customer()?.defaultDiscountRate ?? '';
    for (const line of this.lines()?.controls ?? []) {
      if (line.controls.discountRate.pristine) {
        line.controls.discountRate.setValue(rate);
      }
    }
  }

  /** An amount the API wrote as zero at any scale, "0" or "0.000". */
  protected isZero(amount: string): boolean {
    return /^-?[0.]+$/.test(amount);
  }

  protected chargesDocumentTax(taxId: string): boolean {
    return this.documentTaxes().includes(taxId);
  }

  protected toggleDocumentTax(taxId: string, checked: boolean): void {
    const others = this.documentTaxes().filter((id) => id !== taxId);
    this.documentTaxes.set(checked ? [...others, taxId] : others);
  }

  protected async save(): Promise<void> {
    const companyId = this.company()?.id;
    const input = this.collect();
    if (!companyId || input === null) return;
    const id = this.id();
    if (id === null) {
      const created = await this.facade.create(companyId, input);
      if (created !== null) {
        this.feedback.success('invoices.saved');
        await this.router.navigate(['/invoices', created.id], { replaceUrl: true });
      }
    } else if ((await this.facade.revise(companyId, id, input)) !== null) {
      const form = this.form();
      if (form !== null) this.sync.savedHere(form);
      this.feedback.success('invoices.saved');
    }
  }

  protected async issue(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    const input = this.collect();
    if (!companyId || id === null || input === null) return;
    await this.facade.reviseAndIssue(companyId, id, input);
  }

  protected async cancel(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    this.confirmingCancel.set(false);
    if (!companyId || id === null || this.busy()) return;
    await this.facade.cancel(companyId, id);
  }

  protected async creditNote(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (!companyId || id === null || this.busy()) return;
    const credit = await this.facade.creditNote(companyId, id);
    if (credit !== null) {
      await this.router.navigate(['/invoices', credit.id]);
    }
  }

  protected async recordPayment(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    const group = this.payment()?.group;
    if (!companyId || id === null || group === undefined || this.busy()) return;
    if (group.invalid) {
      group.markAllAsTouched();
      return;
    }
    if (await this.facade.recordPayment(companyId, id, paymentInput(group.getRawValue()))) {
      this.feedback.success('invoices.payments.recorded');
    }
  }

  protected async deletePayment(payment: Payment): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    this.confirmingPaymentDelete.set(null);
    if (!companyId || id === null || this.busy()) return;
    await this.facade.deletePayment(companyId, id, payment.id);
  }

  /** The document taxes the saved document is charged, as a new form would choose them. */
  private savedDocumentTaxes(): readonly string[] {
    const current = this.current();
    const options = this.options();
    if (!current || options === null) return [];
    return current.documentTaxComponentIds ?? defaultDocumentTaxes(options, current.customerId);
  }

  private linesText(lines: unknown, documentTaxes: readonly string[]): string {
    return JSON.stringify({ lines, documentTaxes: [...documentTaxes].sort() });
  }

  /** The header, the lines and the document taxes as the API takes them, or null after showing what is wrong. */
  private collect(): InvoiceInput | null {
    const form = this.form();
    const lines = this.lines();
    if (form === null || lines === null || this.busy()) return null;
    if (form.invalid || lines.invalid) {
      form.markAllAsTouched();
      lines.markAllAsTouched();
      return null;
    }
    return invoiceInput(form.getRawValue(), lines, this.documentTaxes());
  }
}
