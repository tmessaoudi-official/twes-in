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
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { RecordHistory } from '../activity/record-history';
import { FormatFacade } from '../shared/i18n/format-facade';
import { DescriptorForm } from '../shared/form/descriptor-form';
import type { FieldError } from '../shared/form/form-builder';
import { refusedError } from '../shared/form/refusal-words';
import { RefusalFigures } from '../shared/form/refusal-figures';
import { RefusalLine } from '../shared/documents/refusal-line';
import type { FieldRefusal } from '../shared/form/field-refusals';
import { Select, type SelectOption } from '../shared/form/select';
import { buildFormGroup } from '../shared/form/form-builder';
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
  pickedCustomer,
  shownStatus,
  stillOwed,
} from './invoice-forms';
import { PickField, type PickOption } from '../shared/form/pick-field';
import { InvoiceLines } from './invoice-lines';
import { ProductScans } from '../products/product-scans';
import { type Scan, ScanBus, type ScanOutcome } from '../shared/scan/scan-bus';
import { placedOutcome, scanIntoLines, scannedLot } from '../shared/scan/scan-lines';
import { CustomerDisplay } from '../shared/customer-display/customer-display';
import { linePriced } from '../shared/customer-display/line-price';
import { liveRecord } from '../shared/form/live-record';
import { LiveChanges } from '../shared/realtime/live-changes';
import { PartConflict } from '../shared/form/part-conflict';
import { RecordChanged } from '../shared/form/record-changed';
import { InvoicesFacade } from './invoices-facade';
import {
  INVOICE_DELIVERY_TONES,
  INVOICE_STATUS_TONES,
  INVOICE_STATUS_STAGES,
  type InvoiceDeliveryNote,
  type InvoiceLineDelivery,
  type CustomerOption,
  type InvoiceInput,
  type InvoiceRow,
  type IssuePreview,
  OPERATION_CATEGORIES,
  type OperationCategory,
  type Payment,
  type ProductOption,
} from './invoices-types';
import { Feedback } from '../shared/feedback/feedback';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { revertToSaved, unsavedChanges } from '../shared/form/dirty-count';
import { MatDialog } from '@angular/material/dialog';
import { firstValueFrom } from 'rxjs';
import { DocumentActions } from '../shared/ui/document-actions';
import type { PlannedAction } from '../shared/actions/planned-actions';
import { onRecord } from '../shared/actions/record-action';
import { kindAmong, type ActionConfirm, type ScreenAction } from '../shared/actions/screen-action';
import { InvoiceActions, invoiceMay, isOpen, isZero } from './invoice-actions';
import { ScreenActions } from '../shared/actions/screen-actions';
import { CreditExcessDialog, type CreditExcessDialogData } from './credit-excess-dialog';
import { InvoiceInstruments } from './invoice-instruments';
import { InvoiceReminders } from './invoice-reminders';
import { type RecordLead, RecordView } from '../shared/form/record-view';
import { taxNames } from './tax-names';
import {
  liveFigures,
  negated,
  savesSomething,
  toDocumentFigures,
} from '../shared/documents/document-figures';
import { QuantityTotalsView } from '../shared/documents/quantity-totals';
import { productsIntoLines, takeInAdded, type TakenIn } from '../shared/documents/add-to';
import { StockMoveFailures } from '../inventory/stock-move-failures';

/**
 * One invoice or credit note: a new draft to fill in, a draft to revise, issue or cancel, or an issued document to
 * print, pay and correct with a credit note. Only a draft changes; the API refuses anything else whatever this
 * screen shows, and works out every figure it shows.
 */
/**
 * What an invoice will offer once its planned modules ship (docs/SPEC.md § 7, 2026-09-26 18:17, row 150), shown
 * « Bientôt » beside what it offers today.
 */
export const INVOICE_PLANNED: readonly PlannedAction[] = [
  { module: 'mailing', label: 'planned_actions.send_email', icon: 'forward_to_inbox' },
  { module: 'whatsapp', label: 'planned_actions.send_whatsapp', icon: 'chat' },
];

@Component({
  selector: 'app-invoice-page',
  imports: [
    StockMoveFailures,
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
  private readonly invoiceActions = inject(InvoiceActions);
  protected readonly auth = inject(AuthFacade);
  private readonly format = inject(FormatFacade);
  private readonly router = inject(Router);
  private readonly productScans = inject(ProductScans);
  private readonly display = inject(CustomerDisplay);
  private readonly linesEditor = viewChild(InvoiceLines);
  private readonly headerEditor = viewChild(DescriptorForm);
  private readonly translate = inject(TranslateService);
  /** Set once the sale was created and is opened at its own address, which the customer display keeps showing. */
  private handedOver = false;
  /**
   * The line the customer display shows, which a scan of this tab's put there. A plain field, not a signal: the line's
   * own price is taken only from figures that arrive after the scan, never from an answer worked out before it.
   */
  private onDisplay: { readonly line: LineGroup; readonly name: string } | null = null;

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
   * `?pay=1` on an issued document: a link straight to « Encaisser », which opens the record with its payment asked
   * once the document is read, where one is still owed, then forgotten by the address.
   */
  readonly pay = input<string | undefined>(undefined);
  /**
   * `?add=<id>&add=<id>`: the products « Ajouter à… » sent here, put on the lines once as scans would be, unsaved until
   * the person saves, then forgotten by the address.
   */
  readonly add = input<string | readonly string[] | undefined>(undefined);

  protected readonly id = computed(() => this.invoiceId() ?? null);
  protected readonly options = this.facade.options;
  protected readonly taxName = computed(() => taxNames(this.options()?.taxes ?? []));
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  private readonly refusalFigures = inject(RefusalFigures);
  /** The line above the form: what no field took, or the error's own words. */
  protected readonly refusalLine = new RefusalLine(
    this.facade.refusals,
    'invoices.errors.',
    this.translate,
    this.refusalFigures,
  );
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
  protected readonly deliveryTones = INVOICE_DELIVERY_TONES;
  /** The notes handing over the issued invoice's goods, the API telling them only to whoever may read notes. */
  protected readonly deliveredBy = computed<InvoiceDeliveryNote[]>(
    () => this.current()?.deliveredBy ?? [],
  );
  /** Each line of goods with what is delivered and left, shown once a note hands over any of it. */
  protected readonly deliveries = computed<InvoiceLineDelivery[]>(() =>
    this.deliveredBy().length > 0 ? (this.current()?.deliveries ?? []) : [],
  );
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
    version: () => this.current()?.version ?? null,
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
      return {
        companyId,
        id,
        input: { ...input, lines: sent },
        positions,
        count: lines.length,
      };
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
  /** The customer, read first inside « Client et établissement » once the document no longer changes. */
  protected readonly viewLead = computed<RecordLead>(() => ({
    section: 'parties',
    label: 'invoices.fields.customerId',
    value: this.customerOfRecord(),
    testId: 'invoice-view-customer',
  }));
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
  /** What the API refused the customer for (deactivated, merged into another), said under its picker. */
  protected readonly customerRefused = signal<FieldError | null>(null);
  /** What the API refused the document's taxes for, said under them. */
  protected readonly documentTaxesRefused = signal<FieldError | null>(null);
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

  /**
   * Whether a credit note's figures leave out the stamp chosen on it because the credit note completing its invoice
   * gives it back, as the API says: a stamp the country keeps, or the invoice never charged, is not promised back.
   */
  protected readonly stampHeldBack = computed(() => {
    const totals = this.isCreditNote() && this.editable() ? this.figures() : null;
    if (totals === null) return false;
    const awaited = new Set(
      totals.chargesLeftOut
        .filter((charge) => charge.reason === 'given_back_by_completing_note')
        .map((charge) => charge.code),
    );
    const chosen = this.documentTaxes();
    return this.documentTaxChoices().some(
      (tax) => tax.family === 'stamp' && chosen.includes(tax.id) && awaited.has(tax.code),
    );
  });

  protected readonly documentTaxSelectOptions = computed<SelectOption[]>(() =>
    this.documentTaxChoices().map((tax) => ({
      value: tax.id,
      label: tax.name,
      testId: `document-tax-${tax.code}`,
    })),
  );

  /** What the document will offer once its planned modules ship, from the moment it exists. */
  protected readonly planned = computed(() => (!this.current() ? [] : INVOICE_PLANNED));
  /**
   * What the document offers, declared once for the bar beside its title (design review finding 3). The state's
   * next step is the primary: issuing a draft, recording a payment on an open invoice. Cancelling is destructive,
   * so it is in "⋮" and asks first; a credit note is rare rather than destructive, and sits there too.
   */
  protected readonly actions = computed<ScreenAction[]>(() => {
    const busy = this.busy();
    return [
      ...this.screenRevert(busy),
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
        // Numbering is for good, so neither a click nor the bare key issues unasked (EFF-01, row 45). What is typed is
        // saved first, so the question quotes the figures that will be issued, and keeping the draft keeps them.
        confirm: this.issueConfirm(),
        before: () => this.saveBeforeIssue(),
        confirmNow: () => this.issueConfirm(),
      },
      {
        id: 'customer-display',
        label: 'customer_display.open',
        icon: 'connected_tv',
        rare: true,
        run: () => this.display.openWindow(),
        shown: this.editable(),
      },
      ...onRecord(this.recordActions(), this.current(), busy),
    ];
  });

  /**
   * What can be done to the document, declared once with the sheet and the list's rows: this page adds what only it
   * knows, the customer's credit, and reads the document again after an action changed it.
   */
  private readonly recordActions = computed(() =>
    this.invoiceActions.declare({
      creditBalance: () => this.creditBalance(),
      changed: () => this.reread(),
      gone: () => void this.leaveGone(),
    }),
  );
  /** The draft is deleted: what was typed in it goes with it, and the list is where the person was. */
  private async leaveGone(): Promise<void> {
    this.unsaved.savedAndLeaving();
    await this.router.navigate(['/invoices']);
  }
  /** « Rétablir »: what was typed goes back to the saved document (docs/SPEC.md § 7, 2026-10-11 08:26). */
  private screenRevert(busy: boolean): ScreenAction[] {
    return [
      {
        id: 'revert',
        label: 'form.revert',
        icon: 'restart_alt',
        disabled: busy,
        run: () => this.revert(),
        shown: this.editable() && this.form() !== null && this.changes() > 0,
      },
    ];
  }
  /** Everything typed and not saved: the header, the lines, who the document is for. */
  protected readonly changes = computed(
    () => this.headerChanges() + this.lineChanges() + this.customerChanges(),
  );

  /**
   * The number the draft would carry if issued now, with what it would state its operations are where the law asks,
   * said on the question before issuing; null until known.
   */
  private readonly nextNumber = signal<IssuePreview | null>(null);
  /** The category of operations chosen in the form, saved or not; null for « from the lines » or no such field. */
  private readonly chosenOperations = computed<OperationCategory | null>(() => {
    this.typed();
    const value = this.form()?.getRawValue()['operationCategory'];
    return OPERATION_CATEGORIES.find((category) => category === value) ?? null;
  });
  /**
   * What issuing will do, said precisely before it is done (docs/SPEC.md § 7, 2026-09-26 23:04): the number, the
   * total and who it is for, and where the law asks, what its operations will be stated to be (the choice in the form,
   * else what the saved lines' products say), or that it must be said first. Until the number is known the question
   * says what issuing does in general.
   */
  private issuePreview(): { message: string; messageParams?: Record<string, string> } {
    const current = this.current();
    const preview = this.nextNumber();
    const scale = this.scale();
    const creditNote = this.isCreditNote();
    if (!current || preview === null || scale === null)
      return {
        message: creditNote
          ? 'invoices.actions.issue_credit_note_message'
          : 'invoices.actions.issue_message',
      };
    const operations = this.chosenOperations() ?? preview.operationCategory;
    const asked = this.options()?.operationCategory === true;
    return {
      message: creditNote
        ? 'invoices.actions.issue_credit_note_message_numbered'
        : !asked
          ? 'invoices.actions.issue_message_numbered'
          : `invoices.actions.issue_message_numbered_${operations ?? 'unsaid'}`,
      messageParams: {
        number: preview.number,
        total:
          `${this.format.amount(current.total, scale)} ${this.options()?.currency ?? ''}`.trim(),
        customer: current.customerName,
      },
    };
  }

  private issueConfirm(): ActionConfirm {
    return this.isCreditNote()
      ? {
          kind: 'definitif',
          title: 'invoices.actions.issue_credit_note_title',
          ...this.issuePreview(),
          confirmLabel: 'invoices.actions.confirm_issue',
          keepLabel: 'invoices.actions.keep',
        }
      : {
          kind: 'corrigeable',
          title: 'invoices.actions.issue_title',
          ...this.issuePreview(),
          confirmLabel: 'invoices.actions.confirm_issue',
          keepLabel: 'invoices.actions.keep',
        };
  }

  /**
   * Saves what is typed before issuing is asked about: the question then quotes what the API kept, not the last
   * save. False, and nothing is asked, when the form is refused as it stands or the API refuses the save.
   */
  private async saveBeforeIssue(): Promise<boolean> {
    if (this.changes() === 0) return true;
    const companyId = this.company()?.id;
    const id = this.id();
    const input = this.collect();
    if (!companyId || id === null || input === null) return false;
    if ((await this.facade.revise(companyId, id, input, this.sync.version())) === null) {
      await this.refused();
      return false;
    }
    this.revisedHere();
    return true;
  }

  /** The form now stands on what this tab saved, before anything else is asked of the document. */
  private readonly revisedHere = (): void => {
    const form = this.form();
    if (form !== null) this.sync.savedHere(form);
  };

  /** A save made over a version saved since is merged and said; any other refusal stays as the screen shows it. */
  private async refused(): Promise<void> {
    if (this.facade.error() === 'record_changed') {
      this.facade.clearError();
      await this.sync.savedElsewhere();
      return;
    }
    this.showRefused(this.facade.refusals());
  }

  /**
   * Each field the API refused, said under it: a line's on its line, the customer under its picker, the document's
   * taxes under them, the rest of the header in its form. The line above the form stays for what is refused as a whole.
   */
  private showRefused(refusals: readonly FieldRefusal[]): void {
    if (refusals.length === 0) return;
    const rest = this.linesEditor()?.showRefused(refusals) ?? refusals;
    const said = (field: string): FieldError | null => {
      const refusal = rest.find((each) => each.field === field);
      return refusal === undefined
        ? null
        : refusedError(this.translate, refusal, refusal.params, this.refusalFigures);
    };
    this.customerRefused.set(said('customerId'));
    this.documentTaxesRefused.set(said('documentTaxComponentIds'));
    const header = rest.filter(
      (each) => each.field !== 'customerId' && each.field !== 'documentTaxComponentIds',
    );
    const unplaced = header.length > 0 ? (this.headerEditor()?.showRefused(header) ?? header) : [];
    this.refusalLine.placed(refusals, unplaced);
  }

  private readonly rights = computed(() => this.invoiceActions.rights());
  protected readonly canIssue = computed(() => {
    const current = this.current();
    return !!current && invoiceMay.issue(current, this.rights());
  });
  protected readonly isOpen = computed(() => {
    const current = this.current();
    return !!current && isOpen(current);
  });
  protected readonly showsPayments = computed(() => !this.isCreditNote() && this.isOpen());
  /** What the document's customer has to their credit, read each time the document is; "0" until then. */
  protected readonly creditBalance = signal('0');

  /**
   * A scan on a draft puts its product on the lines as a till does (docs/SPEC.md § 7, 2026-09-23 09:30): the same
   * product in the same unit counts one more, anything else starts a line. Anywhere else — an issued invoice, somebody
   * who may not read the products, a code no product holds — the card of what it names takes the scan instead.
   */
  /** The products « Ajouter à… » sent here, put on the lines as scans would be: priced and taxed by this document's rule. */
  private async takeIn(asked: readonly string[]): Promise<TakenIn | null> {
    const lines = this.lines();
    const options = this.options();
    const companyId = this.company()?.id;
    if (lines === null || options === null || !companyId) return null;
    const found = await this.facade.pickProducts(companyId, { ids: [...asked] });
    const customer = this.customer();
    return productsIntoLines<LineGroup, ProductOption>(
      lines,
      (line) => line.controls,
      asked,
      found,
      (product) => {
        const line = lineGroup(null, options, customer);
        applyProduct(line, product, options, customer?.excludedFamilies ?? []);
        return line;
      },
      (line, product) => this.linesEditor()?.scanned(line, product),
    );
  }

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
    const line = lines.at(placed.index);
    this.linesEditor()?.scanned(line, product);
    // The customer sees the line the scan went onto at the product's shelf price with taxes, which the display labels
    // as such: the line's own price, after a list or a discount, takes its place once the line's figures arrive.
    this.display.show({
      name: product.name,
      quantity: placed.quantity,
      unitPrice: named.unitPriceGross,
      unitPriceNet: named.unitPriceNet,
    });
    this.onDisplay = { line, name: product.name };
    return placedOutcome(
      {
        ...placed,
        undo: () => {
          placed.undo();
          // A count taken back may fall below the list's quantity break the scan reached.
          if (!placed.added) this.linesEditor()?.scanned(line, product);
          this.onDisplay = null;
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
    // A note's change is announced under the note, never under the invoice it delivers: an issued invoice is read
    // again for how far its notes deliver it. No note delivers a draft, which keeps what is typed.
    inject(LiveChanges).reloadOn(
      ['delivery_note'],
      async () => {
        const current = this.current();
        if (current && current.status !== 'draft') await this.reread();
      },
      inject(DestroyRef),
    );
    // Read again whenever the draft is read again: a save, another person's change, another document issued.
    effect(() => {
      const companyId = this.company()?.id;
      const current = this.current();
      const asked = companyId && current && this.canIssue() ? current : null;
      untracked(() => {
        this.nextNumber.set(null);
        if (asked === null || !companyId) return;
        void this.facade.nextNumber(companyId, asked.id).then((preview) => {
          if (this.current() === asked) this.nextNumber.set(preview);
        });
      });
    });
    const scans = inject(ScanBus);
    scans.handle((scan) => this.scanned(scan));
    // What the customer has to their credit, read again each time the document is.
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
    // What the sale comes to as typed while the draft is edited, so the counter never shows the customer an
    // older total; the display shows it only after a scan of this tab's.
    effect(() => {
      const total = this.shownTotals()?.total;
      if (total !== undefined) untracked(() => this.display.total(total));
    });
    effect(() => {
      const figures = this.figures();
      untracked(() => {
        const shown = this.onDisplay;
        const lines = this.lines();
        const options = this.options();
        if (figures === null || shown === null || lines === null || options === null) return;
        const item = linePriced(
          shown.name,
          shown.line.controls.quantity.value,
          figures.lines[lines.controls.indexOf(shown.line)],
          options.currencyScale,
        );
        if (item !== null) this.display.reprice(item);
      });
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
    takeInAdded({
      add: this.add,
      ready: () => {
        const current = this.current();
        if (this.lines() === null) return null;
        // An open draft's lines wait for the customer it names, whose regime says which taxes a line takes.
        if (current && this.editable() && this.customer()?.id !== current.customerId) return null;
        return { id: current?.id ?? null, editable: this.editable() };
      },
      take: (asked) => this.takeIn(asked),
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
    this.customerRefused.set(null);
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

  protected readonly isZero = isZero;

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
      } else {
        await this.refused();
      }
    } else if ((await this.facade.revise(companyId, id, input, this.sync.version())) !== null) {
      this.revisedHere();
      this.feedback.success('invoices.saved');
    } else {
      await this.refused();
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
    let issued = await this.facade.reviseAndIssue(
      companyId,
      id,
      input,
      this.sync.version(),
      this.revisedHere,
    );
    if (issued === null && this.facade.error() === 'excess_to') {
      // The credit note gives back money already paid: say where it goes, then issue it again with that.
      this.facade.clearError();
      const excessTo = await firstValueFrom(
        this.dialog
          .open<CreditExcessDialog, CreditExcessDialogData>(CreditExcessDialog, {
            data: { invoiceNumber: this.current()?.number ?? null },
            autoFocus: 'first-tabbable',
          })
          .afterClosed(),
      );
      if (!excessTo) return;
      issued = await this.facade.reviseAndIssue(
        companyId,
        id,
        input,
        this.sync.version(),
        this.revisedHere,
        excessTo,
      );
    }
    if (issued === null) await this.refused();
    if (issued === null || kind === undefined) return;
    // Its next step, to whoever may take it (docs/SPEC.md § 7, 2026-09-26, row 139).
    const next = this.owes(issued)
      ? { key: 'invoices.suggest.record_payment', run: () => void this.openPayment() }
      : undefined;
    this.feedback.effect(key, {}, kind, next);
  }

  /** The document's payment, asked from the same declaration the list's rows and the sheet use. */
  protected async openPayment(): Promise<void> {
    const current = this.current();
    if (current && invoiceMay.pay(current, this.rights())) {
      await this.invoiceActions.pay(current, () => undefined);
    }
  }

  /** Back to the saved document: its header, its lines, its customer and the taxes it is charged. */
  protected revert(): void {
    const form = this.form();
    const saved = this.savedHeader();
    const current = this.current();
    if (form !== null && saved !== null) revertToSaved(form, saved);
    this.linesVersion.update((version) => version + 1);
    if (current) {
      const known = this.knownCustomers.get(current.customerId);
      if (known) this.customer.set(known);
      this.customerMissing.set(false);
    }
    this.documentTaxes.set(this.savedDocumentTaxes());
  }

  /** Read again after an action of the declaration changed it, which never comes back to this tab as a live change. */
  private async reread(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (companyId && id !== null) await this.facade.loadInvoice(companyId, id);
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
    if (await this.facade.deletePayment(companyId, id, payment.id)) {
      // The row goes and the amount due rises, out of sight on a phone: both are said.
      this.feedback.success('invoices.payments.deleted', {
        amount: this.format.amount(payment.amount, this.scale()),
      });
    }
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
