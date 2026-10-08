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
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { RecordHistory } from '../activity/record-history';
import { FormatFacade } from '../shared/i18n/format-facade';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { Select, type SelectOption } from '../shared/form/select';
import { buildFormGroup } from '../shared/form/form-builder';
import type { FormValues } from '../shared/form/form-types';
import { todayIn } from '../shared/i18n/format';
import { AmountPipe, DayPipe } from '../shared/i18n/format-pipes';
import { StatusBadge } from '../shared/ui/status-badge';
import {
  defaultDocumentTaxes,
  documentTaxOptions,
  invoiceForm,
  figuresReady,
  invoiceInput,
  invoiceValues,
  linesArray,
  type LineGroup,
  lineGroup,
  applyProduct,
  creditNoteForm,
  paymentForm,
  paymentInput,
  paymentValues,
  pickedCustomer,
  shownStatus,
  stillOwed,
  paidInFull,
} from './invoice-forms';
import { PickField, type PickOption } from '../shared/form/pick-field';
import { InvoiceLines } from './invoice-lines';
import { ProductScans } from '../products/product-scans';
import { type Scan, ScanBus, type ScanOutcome } from '../shared/scan/scan-bus';
import { placedOutcome, scanIntoLines, scannedLot } from '../shared/scan/scan-lines';
import { CustomerDisplay } from '../shared/customer-display/customer-display';
import { liveRecord } from '../shared/form/live-record';
import { PartConflict } from '../shared/form/part-conflict';
import { RecordChanged } from '../shared/form/record-changed';
import { InvoicesFacade } from './invoices-facade';
import {
  INVOICE_STATUS_TONES,
  INVOICE_STATUS_STAGES,
  type CustomerOption,
  type InvoiceInput,
  type InvoiceRow,
  type Payment,
} from './invoices-types';
import { Feedback } from '../shared/feedback/feedback';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { unsavedChanges } from '../shared/form/dirty-count';
import { MatDialog } from '@angular/material/dialog';
import { firstValueFrom } from 'rxjs';
import { DocumentActions } from '../shared/ui/document-actions';
import type { PlannedAction } from '../shared/actions/planned-actions';
import { kindAmong, type ScreenAction } from '../shared/actions/screen-action';
import { ScreenActions } from '../shared/actions/screen-actions';
import { CreditExcessDialog } from './credit-excess-dialog';
import { CreditNoteDialog } from './credit-note-dialog';
import { InvoiceInstruments } from './invoice-instruments';
import { InvoiceReminders } from './invoice-reminders';
import { PaymentDialog } from './payment-dialog';
import { OverpaymentDialog } from './overpayment-dialog';
import { overpaymentForm, overpaymentInput, overpaymentValues } from './overpayment-form';
import { RecordView } from '../shared/form/record-view';
import { taxNames } from './tax-names';
import {
  liveFigures,
  negated,
  savesSomething,
  toDocumentFigures,
} from '../shared/documents/document-figures';
import { QuantityTotalsView } from '../shared/documents/quantity-totals';

/**
 * One invoice or credit note: a new draft to fill in, a draft to revise, issue or cancel, or an issued document to
 * print, pay and correct with a credit note. Only a draft changes; the API refuses anything else whatever this
 * screen shows, and works out every figure it shows.
 */
/**
 * What an invoice will offer once its planned modules ship (docs/SPEC.md § 7, 2026-09-26 18:17, row 150), shown
 * « Bientôt » beside what it offers today. A credit note is never made recurring.
 */
export const INVOICE_PLANNED: readonly PlannedAction[] = [
  { module: 'mailing', label: 'planned_actions.send_email', icon: 'forward_to_inbox' },
  { module: 'whatsapp', label: 'planned_actions.send_whatsapp', icon: 'chat' },
  { module: 'recurring', label: 'planned_actions.make_recurring', icon: 'event_repeat' },
];

@Component({
  selector: 'app-invoice-page',
  imports: [
    MatButtonModule,
    MatCardModule,
    FormsModule,
    RouterLink,
    TranslatePipe,
    AmountPipe,
    DayPipe,
    InvoiceInstruments,
    InvoiceReminders,
    DescriptorForm,
    Select,
    DocumentActions,
    RecordView,
    InvoiceLines,
    QuantityTotalsView,
    PartConflict,
    PickField,
    RecordChanged,
    RecordHistory,
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
  protected readonly auth = inject(AuthFacade);
  private readonly format = inject(FormatFacade);
  private readonly router = inject(Router);
  private readonly productScans = inject(ProductScans);
  private readonly display = inject(CustomerDisplay);
  /** Set once the sale was created and is opened at its own address, which the customer display keeps showing. */
  private handedOver = false;

  /** Bound from the route parameter by withComponentInputBinding(); absent on `invoices/new`. */
  readonly invoiceId = input<string | undefined>(undefined);
  /**
   * `?scan=` on a new document: a code a scan card sent here, played once as a scan of this page, so the product lands
   * on the first line by the same rule as any scan — a pack enters its count (docs/SPEC.md § 7, 2026-09-23, slice 2).
   */
  readonly scan = input<string | undefined>(undefined);
  /**
   * `?billTo=` on a new document: the customer « Facturer ce client » names, chosen once as if picked, then
   * forgotten by the address (docs/SPEC.md § 7, 2026-09-26, row 139).
   */
  readonly billTo = input<string | undefined>(undefined);
  /**
   * `?pay=1` on an issued document: the sheet's « Encaisser », which opens the record with its payment asked once the
   * document is read, where one is still owed, then forgotten by the address (docs/SPEC.md § 7, 2026-09-26).
   */
  readonly pay = input<string | undefined>(undefined);

  protected readonly id = computed(() => this.invoiceId() ?? null);
  protected readonly options = this.facade.options;
  protected readonly taxName = computed(() => taxNames(this.options()?.taxes ?? []));
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly scale = computed(() => this.options()?.currencyScale ?? null);
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly today = computed(() => todayIn(this.company()?.timezone));
  protected readonly mayWrite = computed(() => this.auth.hasPermission('invoice.write'));
  protected readonly mayIssue = computed(() => this.auth.hasPermission('invoice.issue'));
  protected readonly mayPay = computed(() => this.auth.hasPermission('payment.write'));
  /** A credit note reverses revenue, so drafting and issuing one is a manager's, where issuing an invoice is a clerk's. */
  protected readonly mayCredit = computed(() => this.auth.hasPermission('invoice.credit'));
  /** The invoice's « Historique », for a reader of the activity journal. */
  protected readonly mayHistory = computed(() => this.auth.hasPermission('audit.read'));
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
  protected readonly mayReadQuotes = computed(
    () => this.auth.hasPermission('quote.read') && this.auth.hasModule('quotes'),
  );
  /**
   * Why the customer cannot change here, as a hint's key, or null: a credit note goes to the customer of the invoice it
   * corrects, and lines taken from delivery notes stay with theirs until they are taken off and saved. The API refuses
   * both; offering the change only to refuse it read as a broken screen (audit A-F2).
   */
  protected readonly customerLock = computed<string | null>(() => {
    const current = this.current();
    if (!current) return null;
    if (current.type === 'credit_note') return 'invoices.form.customer_locked_credit_note';
    const fromNotes = current.lines.some((line) => (line.sourceDeliveryNoteLineId ?? '') !== '');
    return fromNotes ? 'invoices.form.customer_locked_delivery_notes' : null;
  });
  protected readonly shown = computed(() => {
    const current = this.current();
    return current ? shownStatus(current, this.today()) : null;
  });
  protected readonly tones = INVOICE_STATUS_TONES;
  protected readonly stages = INVOICE_STATUS_STAGES;
  /** A credit note reverses revenue, so changing its draft takes the right to draft one, as the API asks. */
  private readonly mayTouch = computed(
    () => this.mayWrite() && (!this.isCreditNote() || this.mayCredit()),
  );
  protected readonly editable = computed(() => {
    const current = this.current();
    return this.mayTouch() && (current === null || current?.status === 'draft');
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
  /** Bumped by every value the form or the lines take, so the figures read what is typed now. */
  private readonly typed = signal(0);
  /** The establishment the document is typed at, whose shelves its lines read; null for the main one. */
  protected readonly typedEstablishment = computed(() => {
    this.typed();
    const value = this.form()?.getRawValue()['establishmentId'];
    return typeof value === 'string' && value !== '' ? value : null;
  });
  /** A credit note takes no goods out, so its lines say nothing of stock. */
  protected readonly showsStock = computed(
    () =>
      this.auth.hasModule('inventory') &&
      this.auth.hasPermission('stock.read') &&
      !this.isCreditNote(),
  );
  /**
   * What the figures are asked for: the document as it would be saved, with the lines that can be worked out yet (a
   * line still missing its quantity, unit or price waits). A document that no longer changes, or that is not for
   * anyone yet, asks nothing.
   */
  private readonly previewDraft = computed(() => {
    this.typed();
    const companyId = this.company()?.id;
    const id = this.id();
    const form = this.form();
    const lines = this.lines();
    const customerId = this.customer()?.id ?? '';
    const documentTaxes = this.documentTaxes();
    if (!this.editable() || !companyId || form === null || lines === null || customerId === '')
      return null;
    return untracked(() => {
      const input = invoiceInput(form.getRawValue(), lines, documentTaxes, customerId);
      const positions = lines.controls.flatMap((line, index) =>
        figuresReady(line) ? [index] : [],
      );
      const sent = positions.map((index) => {
        const line = input.lines[index]!;
        // A line's figures do not depend on its words: one not described yet is still worked out.
        return line.description === '' ? { ...line, description: '…' } : line;
      });
      return { companyId, id, input: { ...input, lines: sent }, positions, count: lines.length };
    });
  });
  /** The figures as the document stands typed, from the API's one calculator, or null until it has answered. */
  protected readonly figures = liveFigures(
    () => this.previewDraft(),
    async ({ companyId, id, input, positions, count }) => {
      const body = await this.facade.preview(companyId, id, input);
      return body === null ? null : toDocumentFigures(body, positions, count);
    },
  );
  protected readonly lineFigures = computed(() => this.figures()?.lines ?? null);
  protected readonly negated = negated;
  /**
   * What the totals card shows: while the draft is edited, the figures as typed, which nothing has paid or credited
   * yet; otherwise the document as saved.
   */
  protected readonly shownTotals = computed(() => {
    const live = this.editable() ? this.figures() : null;
    if (live !== null) {
      return {
        ...live,
        live: true,
        status: 'draft',
        amountDue: live.netToPay,
        amountPaid: '0',
        amountCredited: '0',
      };
    }
    const current = this.current();
    return current ? { ...current, live: false } : null;
  });

  /**
   * What is typed and not saved, counted so that leaving the page asks first (row 45, RCH-01): the header's fields,
   * the lines against the lines the saved document would show, and who the document is for. Each part declares
   * itself to the leave guard.
   */
  private readonly savedHeader = computed(() => {
    const current = this.current();
    const options = this.options();
    return current === undefined || options === null ? null : invoiceValues(current, options);
  });
  protected readonly headerChanges = unsavedChanges(this.form, this.savedHeader);
  private readonly savedLines = computed(() => {
    const current = this.current();
    const options = this.options();
    if (current === undefined || options === null) return null;
    return linesArray(current?.lines ?? [], options, this.customer()).getRawValue();
  });
  protected readonly lineChanges = unsavedChanges(this.lines, this.savedLines);
  /** Picking someone else, or anyone at all on a new document; the document taxes follow the customer. */
  private readonly customerChanges = computed(() => {
    const current = this.current();
    const picked = this.customer()?.id ?? null;
    if (current === undefined || picked === null) return 0;
    const taxesMoved =
      current !== null &&
      this.linesText([], this.documentTaxes()) !== this.linesText([], this.savedDocumentTaxes());
    return (picked !== (current?.customerId ?? null) ? 1 : 0) + (taxesMoved ? 1 : 0);
  });

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
  /**
   * Whom a locked document is for, as it recorded them when it was issued: the customer is a picker beside the form, not
   * a field of its descriptor, so the read view would otherwise never name them.
   */
  protected readonly customerOfRecord = computed(
    () => this.current()?.recordedCustomerName ?? this.customerShown()?.name ?? '',
  );
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

  protected readonly documentTaxSelectOptions = computed<SelectOption[]>(() =>
    this.documentTaxChoices().map((tax) => ({
      value: tax.id,
      label: tax.name,
      testId: `document-tax-${tax.code}`,
    })),
  );

  protected readonly payment = computed(() => {
    const current = this.current();
    if (current === undefined || current === null || !this.canRecordPayment()) return null;
    return untracked(() => ({
      descriptor: paymentForm(),
      group: buildFormGroup(paymentForm(), paymentValues(this.today(), current.amountDue)),
    }));
  });

  /** What the document will offer once its planned modules ship, from the moment it exists. */
  protected readonly planned = computed(() =>
    !this.current()
      ? []
      : INVOICE_PLANNED.filter((action) => !this.isCreditNote() || action.module !== 'recurring'),
  );
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
        // Kept in a phone's bar, or a new document there offers no way to save it.
        keep: true,
        disabled: busy,
        run: () => void this.save(),
        shown: this.editable() && this.form() !== null,
      },
      {
        id: 'issue',
        next: true,
        label: this.isCreditNote()
          ? 'invoices.actions.issue_credit_note'
          : 'invoices.actions.issue',
        icon: 'send',
        primary: true,
        disabled: busy,
        run: () => void this.issue(),
        shown: this.canIssue(),
        // Numbering is for good, so neither a click nor the bare key issues unasked (EFF-01, row 45).
        confirm: this.isCreditNote()
          ? {
              kind: 'definitif',
              title: 'invoices.actions.issue_credit_note_title',
              message: 'invoices.actions.issue_credit_note_message',
              confirmLabel: 'invoices.actions.confirm_issue',
              keepLabel: 'invoices.actions.keep',
            }
          : {
              kind: 'corrigeable',
              title: 'invoices.actions.issue_title',
              ...this.issuePreview(),
              confirmLabel: 'invoices.actions.confirm_issue',
              keepLabel: 'invoices.actions.keep',
            },
      },
      {
        id: 'record-payment',
        label: 'invoices.payments.collect',
        shortcut: 'p',
        next: true,
        icon: 'payments',
        primary: true,
        disabled: busy,
        run: () => void this.openPayment(),
        shown: this.canRecordPayment(),
      },
      {
        id: 'apply-credit',
        label: 'invoices.actions.apply_credit',
        icon: 'savings',
        rare: true,
        disabled: busy,
        run: () => void this.applyCredit(),
        shown: this.canApplyCredit(),
      },
      {
        id: 'record-overpayment',
        label: 'invoices.actions.record_overpayment',
        icon: 'savings',
        rare: true,
        disabled: busy,
        run: () => void this.openOverpayment(),
        shown: this.canRecordOverpayment(),
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
        id: 'print-duplicate',
        label: 'invoices.actions.print_duplicate',
        icon: 'file_copy',
        rare: true,
        href: this.copyUrl('duplicate') ?? undefined,
        shown: this.isOpen() && this.copyUrl('duplicate') !== null,
      },
      {
        id: 'print-current',
        label: 'invoices.actions.print_current',
        icon: 'receipt_long',
        rare: true,
        href: this.copyUrl('current') ?? undefined,
        shown: this.isOpen() && this.copyUrl('current') !== null,
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
        id: 'customer-display',
        label: 'customer_display.open',
        icon: 'connected_tv',
        rare: true,
        run: () => this.display.openWindow(),
        shown: this.editable(),
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
          kind: 'definitif',
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
  private copyUrl(kind: 'duplicate' | 'current'): string | null {
    const companyId = this.company()?.id;
    const current = this.current();
    return companyId && current ? this.facade.pdfCopyUrl(companyId, current.id, kind) : null;
  }

  /** The number the draft would carry if issued now, said on the question before issuing; null until known. */
  private readonly nextNumber = signal<string | null>(null);
  /**
   * What issuing will do, said precisely before it is done (docs/SPEC.md § 7, 2026-09-26 23:04): the number, the
   * total and who it is for. Until the number is known the question says what issuing does in general.
   */
  private issuePreview(): { message: string; messageParams?: Record<string, string> } {
    const current = this.current();
    const number = this.nextNumber();
    const scale = this.scale();
    if (!current || number === null || scale === null)
      return { message: 'invoices.actions.issue_message' };
    return {
      message: 'invoices.actions.issue_message_numbered',
      messageParams: {
        number,
        total:
          `${this.format.amount(current.total, scale)} ${this.options()?.currency ?? ''}`.trim(),
        customer: current.customerName,
      },
    };
  }

  protected readonly canIssue = computed(
    () =>
      this.current()?.status === 'draft' &&
      this.mayWrite() &&
      this.mayIssue() &&
      (!this.isCreditNote() || this.mayCredit()),
  );
  protected readonly canCancel = computed(
    () => this.current()?.status === 'draft' && this.mayTouch(),
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
      this.mayCredit() &&
      !this.isZero(this.current()?.amountDue ?? '0'),
  );
  protected readonly showsPayments = computed(() => !this.isCreditNote() && this.isOpen());
  /** A saved document can be copied into a new draft; a document that does not exist yet cannot. */
  protected readonly canDuplicate = computed(
    () => this.mayWrite() && this.current() !== null && this.current() !== undefined,
  );
  protected readonly canRecordPayment = computed(() => this.owes(this.current()));
  /** What the document's customer has to their credit, read each time the document is; "0" until then. */
  protected readonly creditBalance = signal('0');
  protected readonly canApplyCredit = computed(
    () => this.canRecordPayment() && this.mayPay() && !this.isZero(this.creditBalance()),
  );
  /** Money paid beyond an invoice is kept to the customer's credit once the invoice is paid in full, never before. */
  protected readonly canRecordOverpayment = computed(
    () => paidInFull(this.current()) && this.mayPay(),
  );

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
        lot: scannedLot(product.tracking, named),
        serial: product.tracking === 'serial',
      },
      () => {
        const line = lineGroup(null, options, customer);
        applyProduct(line, product, options, customer?.excludedFamilies ?? []);
        return line;
      },
    );
    if (placed.repeated === true)
      return { kind: 'refused', key: 'scan.serial_present', params: { name: product.name } };
    // The customer sees the line the scan went onto, at the price they pay for one (docs/SPEC.md § 7, slice 6).
    this.display.show({
      name: product.name,
      quantity: placed.quantity,
      unitPrice: named.unitPriceGross,
      unitPriceNet: named.unitPriceNet,
    });
    return placedOutcome(
      {
        ...placed,
        undo: () => {
          placed.undo();
          this.display.clear();
        },
      },
      { name: product.name, unitPrice: product.unitPriceNet },
    );
  }

  constructor() {
    this.unsaved.declare(this.customerChanges);
    // The same list the bar draws also answers the keyboard, the palette and the "?" sheet (row 45): one
    // declaration, so an action cannot be offered in one of them and missing from another.
    inject(ScreenActions).declare(this.actions);
    // Read again whenever the draft is read again: a save, another person's change, another document issued.
    effect(() => {
      const companyId = this.company()?.id;
      const current = this.current();
      const asked = companyId && current && this.canIssue() ? current : null;
      untracked(() => {
        this.nextNumber.set(null);
        if (asked === null || !companyId) return;
        void this.facade.nextNumber(companyId, asked.id).then((number) => {
          if (this.current() === asked) this.nextNumber.set(number);
        });
      });
    });
    const scans = inject(ScanBus);
    scans.handle((scan) => this.scanned(scan));
    // What the sale comes to, each time it is read as saved; the display shows it only after a scan of this tab's.
    effect(() => {
      const companyId = this.company()?.id;
      const current = this.current();
      const mayPay = this.mayPay();
      untracked(async () => {
        const owes = current !== undefined && current !== null && this.owes(current);
        this.creditBalance.set(
          companyId && owes && mayPay
            ? await this.facade.customerCredit(companyId, current.customerId)
            : '0',
        );
      });
    });
    effect(() => {
      const total = this.current()?.total;
      if (total !== undefined) untracked(() => this.display.total(total));
    });
    inject(DestroyRef).onDestroy(() => {
      if (!this.handedOver) this.display.clear();
    });
    // Another document on the same screen is another sale: what the display showed was the last one's.
    let shownId: string | null | undefined;
    effect(() => {
      const id = this.id();
      untracked(() => {
        if (shownId !== undefined && shownId !== id) this.display.clear();
        shownId = id;
      });
    });
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
    let payAskedFor: string | null = null;
    effect(() => {
      const current = this.current();
      if (this.pay() === undefined || !current || current.id !== this.id()) return;
      // Once per document: a payment recorded re-reads it, which must not ask for another.
      if (payAskedFor === current.id) return;
      payAskedFor = current.id;
      untracked(() => {
        void this.router.navigate([], {
          queryParams: { pay: null },
          queryParamsHandling: 'merge',
          replaceUrl: true,
        });
        // openPayment asks nothing where nothing is owed or the member may not record a payment.
        void this.openPayment();
      });
    });
    // Waits for the new document and its options only: lines drawn again before the address forgets the customer
    // must not choose it a second time over another pick. The lines read the customer when they are drawn.
    effect(() => {
      const customerId = this.billTo();
      const companyId = this.company()?.id;
      const ready = this.id() === null && this.current() === null && this.options() !== null;
      if (customerId === undefined || !companyId || !ready) return;
      untracked(async () => {
        void this.router.navigate([], {
          queryParams: { billTo: null },
          queryParamsHandling: 'merge',
          replaceUrl: true,
        });
        const [found] = await this.facade.pickCustomers(companyId, { ids: [customerId] });
        if (found === undefined) return;
        this.knownCustomers.set(found.id, found);
        this.chooseCustomer({ id: found.id, code: found.number, name: found.name });
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
    effect((onCleanup) => {
      const form = this.form();
      const lines = this.lines();
      const bump = (): void => this.typed.update((typed) => typed + 1);
      const subscriptions = [
        form?.valueChanges.subscribe(bump),
        lines?.valueChanges.subscribe(bump),
      ];
      onCleanup(() => subscriptions.forEach((subscription) => subscription?.unsubscribe()));
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
    if (this.error() === 'customer_unavailable') this.facade.clearError();
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

  /** Whether a payment can be recorded against this document, by this member: an open invoice with money owed. */
  private owes(document: InvoiceRow | null | undefined): boolean {
    return stillOwed(document) && this.mayPay();
  }

  /** An amount the API wrote as zero at any scale, "0" or "0.000". */
  protected readonly saves = savesSomething;

  protected isZero(amount: string): boolean {
    return /^-?[0.]+$/.test(amount);
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
        this.handedOver = true;
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
    // Read before issuing: once issued, the screen declares what an issued document offers.
    const kind = kindAmong(this.actions(), 'issue');
    const key = this.isCreditNote() ? 'invoices.credit_note_issued' : 'invoices.issued';
    let issued = await this.facade.reviseAndIssue(companyId, id, input);
    if (issued === null && this.facade.error() === 'excess_to') {
      // The credit note gives back money already paid: say where it goes, then issue it again with that.
      this.facade.clearError();
      const excessTo = await firstValueFrom(
        this.dialog
          .open(CreditExcessDialog, {
            data: { invoiceNumber: this.current()?.number ?? null },
            autoFocus: 'first-tabbable',
          })
          .afterClosed(),
      );
      if (!excessTo) return;
      issued = await this.facade.reviseAndIssue(companyId, id, input, excessTo);
    }
    if (issued === null || kind === undefined) return;
    // Its next step, to whoever may take it (docs/SPEC.md § 7, 2026-09-26, row 139).
    const next = this.owes(issued)
      ? { key: 'invoices.suggest.record_payment', run: () => void this.openPayment() }
      : undefined;
    this.feedback.effect(key, {}, kind, next);
  }

  protected async cancel(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    this.confirmingCancel.set(false);
    if (!companyId || id === null || this.busy()) return;
    const kind = kindAmong(this.actions(), 'cancel');
    const cancelled = await this.facade.cancel(companyId, id);
    if (cancelled !== null && kind !== undefined)
      this.feedback.effect('invoices.cancelled', {}, kind);
  }

  /** Asks why first: a credit note states its reason when it is drafted (docs/SPEC.md § 7, 2026-09-24 22:51). */
  protected async creditNote(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (!companyId || id === null || this.busy()) return;
    const data = {
      descriptor: creditNoteForm(),
      group: buildFormGroup(creditNoteForm(), { reason: '' }),
    };
    const reason = await firstValueFrom(
      this.dialog.open(CreditNoteDialog, { data, autoFocus: 'first-tabbable' }).afterClosed(),
    );
    if (!reason) return;
    const credit = await this.facade.creditNote(companyId, id, reason);
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

  /** Pays as much of the invoice from the customer's credit as it holds and the invoice is due. */
  protected async applyCredit(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (!companyId || id === null || this.busy()) return;
    if (await this.facade.applyCredit(companyId, id)) {
      this.feedback.success('invoices.payments.credit_applied');
    }
  }

  /** What the customer paid beyond this invoice, kept to their credit (a « trop-perçu »). */
  protected async openOverpayment(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (!companyId || id === null || this.busy()) return;
    const descriptor = overpaymentForm();
    const values = await firstValueFrom(
      this.dialog
        .open(OverpaymentDialog, {
          data: { descriptor, group: buildFormGroup(descriptor, overpaymentValues(this.today())) },
          autoFocus: 'first-tabbable',
        })
        .afterClosed(),
    );
    if (values && (await this.facade.overpay(companyId, id, overpaymentInput(values)))) {
      this.feedback.success('invoices.overpayment.recorded');
    }
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

  /** A cheque or traite was cashed: the payment it became and what is still due are read again. */
  protected async instrumentCashed(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (companyId && id !== null) await this.facade.loadInvoice(companyId, id);
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
