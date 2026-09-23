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
import type { FormValues } from '../shared/form/form-types';
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
  type LineGroup,
  lineGroup,
  applyProduct,
  paymentForm,
  paymentInput,
  paymentValues,
  pickedCustomer,
  shownStatus,
} from './invoice-forms';
import { PickField, type PickOption } from '../shared/form/pick-field';
import { InvoiceLines } from './invoice-lines';
import { ProductScans } from '../products/product-scans';
import { type Scan, ScanBus, type ScanOutcome } from '../shared/scan/scan-bus';
import { placedOutcome, scanIntoLines } from '../shared/scan/scan-lines';
import { liveRecord } from '../shared/form/live-record';
import { PartConflict } from '../shared/form/part-conflict';
import { RecordChanged } from '../shared/form/record-changed';
import { InvoicesFacade } from './invoices-facade';
import {
  INVOICE_STATUS_TONES,
  type CustomerOption,
  type InvoiceInput,
  type Payment,
} from './invoices-types';
import { Feedback } from '../shared/feedback/feedback';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { MatDialog } from '@angular/material/dialog';
import { firstValueFrom } from 'rxjs';
import { DocumentActions } from '../shared/ui/document-actions';
import type { ScreenAction } from '../shared/actions/screen-action';
import { ScreenActions } from '../shared/actions/screen-actions';
import { PaymentDialog } from './payment-dialog';
import { RecordView } from '../shared/form/record-view';

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
    DocumentActions,
    RecordView,
    InvoiceLines,
    PartConflict,
    PickField,
    RecordChanged,
    StatusBadge,
  ],
  templateUrl: './invoice-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class InvoicePage {
  private readonly facade = inject(InvoicesFacade);
  private readonly unsaved = inject(UnsavedChanges);
  private readonly dialog = inject(MatDialog);
  private readonly feedback = inject(Feedback);
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);
  private readonly productScans = inject(ProductScans);

  /** Bound from the route parameter by withComponentInputBinding(); absent on `invoices/new`. */
  readonly invoiceId = input<string | undefined>(undefined);
  /**
   * `?scan=` on a new document: a code a scan card sent here, played once as a scan of this page, so the product lands
   * on the first line by the same rule as any scan — a pack enters its count (docs/SPEC.md § 7, 2026-09-23, slice 2).
   */
  readonly scan = input<string | undefined>(undefined);

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
  protected readonly descriptor = computed(() => {
    const options = this.options();
    const current = this.current();
    if (options === null || current === undefined) return null;
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
      return linesArray(current?.lines ?? [], options, this.customer());
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
          return this.linesText(
            linesArray(current.lines, options, this.customer()).getRawValue(),
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

  /**
   * Who the document is for, as the picker answered it: the whole row, so the taxes its regime refuses and its
   * default discount are known without the company's book ever being read.
   */
  protected readonly customer = signal<CustomerOption | null>(null);
  protected readonly customerShown = computed(() => pickedCustomer(this.customer()));
  /**
   * The document read rather than filled in, once it no longer changes (design review finding 3). A locked
   * document was a form with every control disabled, which says "you may not" where the truth is "it no longer
   * changes", and kept an empty box for every field nobody filled in.
   */
  protected readonly asView = computed(() => !this.editable() && this.current() != null);
  /** What the read view shows for the customer, which is a name rather than the id the control holds. */
  protected readonly viewPicked = computed<Record<string, string>>(() => {
    const customer = this.customerShown();
    const picked: Record<string, string> = {};
    if (customer !== null) picked['customerId'] = customer.name;
    return picked;
  });
  /** Every customer any picker here has answered, so what is chosen can be found again from the option's id. */
  private readonly knownCustomers = new Map<string, CustomerOption>();
  /** Set when saving was asked for with nobody named, since the customer is not a field the form can mark. */
  protected readonly customerMissing = signal(false);
  protected readonly searchCustomers = async (words: string): Promise<readonly PickOption[]> => {
    const companyId = this.company()?.id;
    if (!companyId) return [];
    const found = await this.facade.pickCustomers(companyId, { words });
    for (const customer of found) this.knownCustomers.set(customer.id, customer);
    return found.map((customer) => pickedCustomer(customer) as PickOption);
  };
  /** The fixed charges and withholdings chosen for the document, prefilled with what the API would charge. */
  protected readonly documentTaxes = signal<readonly string[]>([]);
  protected readonly documentTaxChoices = computed(() => {
    const options = this.options();
    return options === null
      ? []
      : documentTaxOptions(options, this.customer(), this.documentTaxes());
  });

  protected readonly payment = computed(() => {
    const current = this.current();
    if (current === undefined || current === null || !this.canRecordPayment()) return null;
    return untracked(() => ({
      descriptor: paymentForm(),
      group: buildFormGroup(paymentForm(), paymentValues(this.today(), current.amountDue)),
    }));
  });

  /**
   * What the document offers, declared once for the bar beside its title (design review finding 3). The state's
   * next step is the primary: issuing a draft, recording a payment on an open invoice. Cancelling is destructive,
   * so it is in "⋮" and asks first; a credit note is rare rather than destructive, and sits there too.
   */
  protected readonly actions = computed<ScreenAction[]>(() => {
    const busy = this.busy();
    return [
      {
        id: 'save',
        label: 'invoices.actions.save',
        shortcut: 's',
        icon: 'save',
        disabled: busy,
        run: () => void this.save(),
        shown: this.editable() && this.form() !== null,
      },
      {
        id: 'issue',
        shortcut: 'e',
        label: this.isCreditNote()
          ? 'invoices.actions.issue_credit_note'
          : 'invoices.actions.issue',
        icon: 'send',
        primary: true,
        disabled: busy,
        run: () => void this.issue(),
        shown: this.canIssue(),
      },
      {
        id: 'record-payment',
        label: 'invoices.payments.record',
        shortcut: 'p',
        icon: 'payments',
        primary: true,
        disabled: busy,
        run: () => void this.openPayment(),
        shown: this.canRecordPayment(),
      },
      {
        id: 'pdf',
        label:
          this.current()?.status === 'draft'
            ? 'invoices.actions.pdf_draft'
            : 'invoices.actions.pdf',
        icon: 'picture_as_pdf',
        href: this.pdfUrl() ?? undefined,
        shown: this.pdfUrl() !== null,
      },
      {
        id: 'duplicate',
        label: 'invoices.actions.duplicate',
        icon: 'content_copy',
        disabled: busy,
        run: () => void this.duplicate(),
        shown: this.canDuplicate(),
      },
      {
        id: 'credit-note',
        label: 'invoices.actions.credit_note',
        icon: 'undo',
        rare: true,
        disabled: busy,
        run: () => void this.creditNote(),
        shown: this.canCredit(),
      },
      {
        id: 'cancel',
        label: 'invoices.actions.cancel',
        icon: 'block',
        destructive: true,
        disabled: busy,
        run: () => void this.cancel(),
        shown: this.canCancel(),
        confirm: {
          title: 'invoices.actions.cancel_title',
          message: 'invoices.actions.cancel_message',
          confirmLabel: 'invoices.actions.confirm_cancel',
          keepLabel: 'invoices.actions.keep',
        },
      },
    ];
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
  /** A saved document can be copied into a new draft; a document that does not exist yet cannot. */
  protected readonly canDuplicate = computed(
    () => this.mayWrite() && this.current() !== null && this.current() !== undefined,
  );
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

  /**
   * A scan on a draft puts its product on the lines as a till does (docs/SPEC.md § 7, 2026-09-23 09:30): the same
   * product in the same unit counts one more, anything else starts a line. Anywhere else — an issued invoice, somebody
   * who may not read the products, a code no product holds — the card of what it names takes the scan instead.
   */
  private async scanned(scan: Scan): Promise<ScanOutcome> {
    const lines = this.lines();
    const options = this.options();
    const companyId = this.company()?.id;
    if (
      !this.editable() ||
      !this.auth.hasPermission('product.read') ||
      lines === null ||
      options === null ||
      !companyId
    ) {
      return { kind: 'unclaimed' };
    }
    const named = await this.productScans.named(scan.code);
    if (named === null) return { kind: 'unclaimed' };
    if (!named.isActive)
      return { kind: 'refused', key: 'scan.retired', params: { name: named.name } };
    const [product] = await this.facade.pickProducts(companyId, { ids: [named.productId] });
    if (product === undefined) return { kind: 'unclaimed' };

    const customer = this.customer();
    const placed = scanIntoLines<LineGroup>(
      lines,
      (line) => line.controls,
      {
        productId: product.id,
        unitId: product.unitId,
        count: Math.max(named.quantity, 1) * scan.times,
      },
      () => {
        const line = lineGroup(null, options, customer);
        applyProduct(line, product, options, customer?.excludedFamilies ?? []);
        return line;
      },
    );
    return placedOutcome(placed, product.name);
  }

  constructor() {
    // The same list the bar draws also answers the keyboard, the palette and the "?" sheet (row 45): one
    // declaration, so an action cannot be offered in one of them and missing from another.
    inject(ScreenActions).declare(this.actions);
    const scans = inject(ScanBus);
    scans.handle((scan) => this.scanned(scan));
    effect(() => {
      // Played once per code: the address then forgets it. Lines drawn again from scratch before that happens take
      // it again, which is right, since the lines it went onto are gone.
      const code = this.scan();
      if (code === undefined || this.id() !== null || this.lines() === null) return;
      untracked(() => {
        void scans.receive(code, 'wedge');
        // The address forgets it, so a reload does not add the product a second time.
        void this.router.navigate([], {
          queryParams: { scan: null },
          queryParamsHandling: 'merge',
          replaceUrl: true,
        });
      });
    });
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
    // An open document names its customer by id alone; the picker is shown the row that id resolves to.
    effect(() => {
      const companyId = this.company()?.id;
      const current = this.current();
      if (!companyId || current === undefined || current === null) return;
      const customerId = current.customerId;
      untracked(async () => {
        if (this.customer()?.id !== customerId) {
          const known = this.knownCustomers.get(customerId);
          const [found] = known
            ? [known]
            : await this.facade.pickCustomers(companyId, { ids: [customerId] });
          if (found) this.knownCustomers.set(found.id, found);
          this.customer.set(found ?? null);
        }
        // What the document itself is charged, or — where it named nothing — what this customer would be.
        const options = this.options();
        this.documentTaxes.set(
          current.documentTaxComponentIds ??
            (options === null ? [] : defaultDocumentTaxes(options, this.customer())),
        );
      });
    });
  }

  /** Naming another customer charges the document what that customer would be charged, and discounts its lines so. */
  protected chooseCustomer(option: PickOption | null): void {
    const customer = option === null ? null : (this.knownCustomers.get(option.id) ?? null);
    this.customer.set(customer);
    this.customerMissing.set(customer === null);
    const options = this.options();
    this.documentTaxes.set(options === null ? [] : defaultDocumentTaxes(options, customer));
    this.applyCustomerDiscount();
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
        // It exists now: going to it is not leaving unsaved work, though the form still holds what was
        // typed and the record holds what the API answered (row 45's leave guard, 2026-09-20).
        this.unsaved.savedAndLeaving();
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

  /** Asked in a dialog rather than in a form at the foot of the page (design review finding 3). */
  protected async openPayment(): Promise<void> {
    const payment = this.payment();
    if (payment === null || this.busy()) return;
    const values = await firstValueFrom(
      this.dialog.open(PaymentDialog, { data: payment, autoFocus: 'first-tabbable' }).afterClosed(),
    );
    if (values) await this.recordPayment(values);
  }

  protected async recordPayment(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (!companyId || id === null || this.busy()) return;
    if (await this.facade.recordPayment(companyId, id, paymentInput(values))) {
      this.feedback.success('invoices.payments.recorded');
    }
  }

  /** A copy of this document as a new draft, which is where the person then continues. */
  protected async duplicate(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (!companyId || id === null || this.busy()) return;
    const copy = await this.facade.duplicate(companyId, id);
    if (copy !== null) {
      this.feedback.success('invoices.duplicated');
      await this.router.navigate(['/invoices', copy.id]);
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
    return current.documentTaxComponentIds ?? defaultDocumentTaxes(options, this.customer());
  }

  private linesText(lines: unknown, documentTaxes: readonly string[]): string {
    return JSON.stringify({ lines, documentTaxes: [...documentTaxes].sort() });
  }

  /** The header, the lines and the document taxes as the API takes them, or null after showing what is wrong. */
  private collect(): InvoiceInput | null {
    const form = this.form();
    const lines = this.lines();
    if (form === null || lines === null || this.busy()) return null;
    const customerId = this.customer()?.id ?? '';
    this.customerMissing.set(customerId === '');
    if (form.invalid || lines.invalid || customerId === '') {
      form.markAllAsTouched();
      lines.markAllAsTouched();
      return null;
    }
    return invoiceInput(form.getRawValue(), lines, this.documentTaxes(), customerId);
  }
}
