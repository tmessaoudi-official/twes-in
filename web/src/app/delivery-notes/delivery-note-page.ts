// SPDX-License-Identifier: AGPL-3.0-or-later

import { RecordDeletes } from '../shared/records/record-deletes';
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  ElementRef,
  inject,
  input,
  signal,
  untracked,
  viewChild,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatIconModule } from '@angular/material/icon';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DescriptorForm } from '../shared/form/descriptor-form';
import type { FieldError } from '../shared/form/form-builder';
import type { FieldRefusal } from '../shared/form/field-refusals';
import { RefusalFigures } from '../shared/form/refusal-figures';
import { RefusalLine } from '../shared/documents/refusal-line';
import { refusedError } from '../shared/form/refusal-words';
import { liveRecord } from '../shared/form/live-record';
import { PartConflict } from '../shared/form/part-conflict';
import { RecordChanged } from '../shared/form/record-changed';
import { buildFormGroup } from '../shared/form/form-builder';
import { AmountPipe, DayPipe, MomentPipe } from '../shared/i18n/format-pipes';
import { StatusBadge } from '../shared/ui/status-badge';
import { fillAddress, holdsAddress } from '../shared/form/address-fill';
import {
  DELIVERY_ADDRESS_FIELDS,
  deliveryNoteForm,
  deliveryNoteInput,
  deliveryNoteValues,
  figuresReady,
  linesArray,
  type LineGroup,
  lineGroup,
  applyProduct,
  pickedCustomer,
} from './delivery-note-forms';
import { ProductScans } from '../products/product-scans';
import { type Scan, ScanBus, type ScanOutcome } from '../shared/scan/scan-bus';
import { placedOutcome, scanIntoLines, scannedLot } from '../shared/scan/scan-lines';
import { CustomerDisplay } from '../shared/customer-display/customer-display';
import { linePriced } from '../shared/customer-display/line-price';
import { PickField, type PickOption } from '../shared/form/pick-field';
import { DeliveryNoteCreditNotice } from './delivery-note-credit-notice';
import { DeliveryNoteLines } from './delivery-note-lines';
import { DeliveryNotesFacade } from './delivery-notes-facade';
import {
  type CustomerOption,
  DELIVERY_NOTE_STATUS_TONES,
  DELIVERY_NOTE_STATUS_STAGES,
  type DeliveryNoteInput,
  type DeliveryNoteRow,
  deliversInvoice,
  type ProductOption,
  type TaxFamily,
} from './delivery-notes-types';
import { productsIntoLines, takeInAdded, type TakenIn } from '../shared/documents/add-to';
import { Feedback } from '../shared/feedback/feedback';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { unsavedChanges } from '../shared/form/dirty-count';
import { MatDialog } from '@angular/material/dialog';
import { firstValueFrom } from 'rxjs';
import { DocumentActions } from '../shared/ui/document-actions';
import type { PlannedAction } from '../shared/actions/planned-actions';
import { kindAmong, type ScreenAction } from '../shared/actions/screen-action';
import { ScreenActions } from '../shared/actions/screen-actions';
import { LiveChanges } from '../shared/realtime/live-changes';
import type { FormAnswer } from '../shared/feedback/request-state';
import { DeliverDialog, type DeliverDialogData } from './deliver-dialog';
import {
  type Receiver,
  type ReceiverContact,
  receiverChoice,
  receiverForm,
} from '../shared/receivers/receiver';
import { ReceiverDialog, type ReceiverDialogData } from '../shared/receivers/receiver-dialog';
import { ReceiverField } from '../shared/receivers/receiver-field';
import { sayReceiverUndoRefused } from '../shared/receivers/receiver-undo';
import {
  InvoicePartDialog,
  type InvoicePartData,
  type InvoicePartLine,
} from './invoice-part-dialog';
import {
  askInvoiceTarget,
  type InvoiceTarget,
  NOTE_INVOICE_TARGET,
} from '../shared/documents/invoice-target';
import { type RecordLead, RecordView } from '../shared/form/record-view';
import { taxNames } from '../invoices/tax-names';
import { QuantityTotalsView } from '../shared/documents/quantity-totals';
import { liveFigures, toDocumentFigures } from '../shared/documents/document-figures';
import { StockMoveFailures } from '../inventory/stock-move-failures';

/**
 * One delivery note: a new draft to fill in, a draft to revise and validate, or a numbered note to deliver, cancel
 * and print. Only a draft changes; the API refuses anything else whatever this screen shows.
 */
/** What a delivery note will offer once its planned modules ship (docs/SPEC.md § 7, 2026-09-26 18:17, row 150), shown « Bientôt » beside what it offers today. */
export const DELIVERY_NOTE_PLANNED: readonly PlannedAction[] = [
  { module: 'mailing', label: 'planned_actions.send_email', icon: 'forward_to_inbox' },
  { module: 'whatsapp', label: 'planned_actions.send_whatsapp', icon: 'chat' },
];

@Component({
  selector: 'app-delivery-note-page',
  imports: [
    QuantityTotalsView,
    MatButtonModule,
    MatCardModule,
    MatIconModule,
    MatFormFieldModule,
    MatInputModule,
    RouterLink,
    TranslatePipe,
    AmountPipe,
    DayPipe,
    MomentPipe,
    DescriptorForm,
    DeliveryNoteLines,
    DeliveryNoteCreditNotice,
    StockMoveFailures,
    DocumentActions,
    RecordView,
    PartConflict,
    PickField,
    RecordChanged,
    ReceiverField,
    StatusBadge,
  ],
  templateUrl: './delivery-note-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DeliveryNotePage {
  private readonly facade = inject(DeliveryNotesFacade);
  private readonly unsaved = inject(UnsavedChanges);
  private readonly dialog = inject(MatDialog);
  private readonly feedback = inject(Feedback);
  protected readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);
  private readonly deletes = inject(RecordDeletes);
  private readonly productScans = inject(ProductScans);
  private readonly display = inject(CustomerDisplay);
  private readonly linesEditor = viewChild(DeliveryNoteLines);
  private readonly headerEditor = viewChild(DescriptorForm);
  private readonly translate = inject(TranslateService);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  /** Set once the note was created and is opened at its own address, which the customer display keeps showing. */
  private handedOver = false;
  /**
   * The line the customer display shows, which a scan of this tab's put there. A plain field, not a signal: the line's
   * own price is taken only from figures that arrive after the scan, never from an answer worked out before it.
   */
  private onDisplay: { readonly line: LineGroup; readonly name: string } | null = null;

  /** Bound from the route parameter by withComponentInputBinding(); absent on `delivery-notes/new`. */
  readonly deliveryNoteId = input<string | undefined>(undefined);
  /**
   * `?scan=` on a new document: a code a scan card sent here, played once as a scan of this page, so the product lands
   * on the first line by the same rule as any scan — a pack enters its count (docs/SPEC.md § 7, 2026-09-23, slice 2).
   */
  readonly scan = input<string | undefined>(undefined);
  /**
   * `?add=<id>&add=<id>`: the products « Ajouter à… » sent here, put on the lines once as scans would be, unsaved until
   * the person saves, then forgotten by the address.
   */
  readonly add = input<string | readonly string[] | undefined>(undefined);

  protected readonly id = computed(() => this.deliveryNoteId() ?? null);
  protected readonly scale = computed(() => this.options()?.currencyScale ?? null);
  protected readonly tones = DELIVERY_NOTE_STATUS_TONES;
  protected readonly stages = DELIVERY_NOTE_STATUS_STAGES;
  protected readonly options = this.facade.options;
  protected readonly taxName = computed(() => taxNames(this.options()?.taxes ?? []));
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  private readonly refusalFigures = inject(RefusalFigures);
  /** The line above the form: what no field took, or the error's own words. */
  protected readonly refusalLine = new RefusalLine(
    this.facade.refusals,
    'delivery_notes.errors.',
    this.translate,
    this.refusalFigures,
  );
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('delivery_note.write'));
  /** The credit notice shows the customer's account, which takes the right to read the customer and its invoices. */
  protected readonly mayReadAccount = computed(
    () => this.auth.hasPermission('customer.read') && this.auth.hasPermission('invoice.read'),
  );
  protected readonly mayValidate = computed(() =>
    this.auth.hasPermission('delivery_note.validate'),
  );
  protected readonly confirmingCancel = signal(false);

  /** Null while a new note is filled in; undefined until the note asked for has been read. */
  protected readonly current = computed(() => {
    const id = this.id();
    if (id === null) return null;
    const note = this.facade.note();
    return note?.id === id ? note : undefined;
  });
  protected readonly editable = computed(() => {
    const current = this.current();
    return this.mayWrite() && (current === null || current?.status === 'draft');
  });
  protected readonly descriptor = computed(() => {
    const options = this.options();
    const current = this.current();
    if (options === null || current === undefined) return null;
    return deliveryNoteForm(options, current);
  });
  /**
   * What the form is of: the note, its state and the fields shown. Reading the note again yields new objects with the
   * same content, and must not rebuild the form over what is being typed; another note or another state must.
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
      return buildFormGroup(descriptor, deliveryNoteValues(current, options));
    });
  });
  protected readonly isLinesConflict = (conflict: { field: string }): boolean =>
    conflict.field === 'lines';
  protected readonly isReceiverConflict = (conflict: { field: string }): boolean =>
    conflict.field === 'receivedBy';
  /** Bumped to show the saved lines again in place of the ones shown. */
  private readonly linesVersion = signal(0);
  protected readonly lines = computed(() => {
    if (this.formKey() === null) return null;
    this.linesVersion();
    return untracked(() => {
      const options = this.options();
      const current = this.current();
      if (options === null || current === undefined) return null;
      return linesArray(current?.lines ?? [], options);
    });
  });

  /** Who will receive the goods, as the draft names them; keyed like the form, so a reload does not undo typing. */
  protected readonly receiver = computed(() => {
    if (this.formKey() === null) return null;
    return untracked(() => receiverForm(this.current()?.receivedBy ?? null));
  });
  /** The draft asks who will receive unless the customer's notes name nobody. */
  protected readonly receiverAsked = computed(
    () => this.editable() && (this.current()?.receiverRule ?? 'optional') !== 'off',
  );
  /** The customer's contacts « Reçu par » offers, read again when the note goes to another customer. */
  protected readonly receiverContacts = signal<readonly ReceiverContact[]>([]);
  private readonly savedReceiver = computed(() => {
    const current = this.current();
    if (current === undefined) return null;
    const receivedBy = current?.receivedBy ?? null;
    return {
      name: receivedBy?.name ?? '',
      contactId: receivedBy?.contactId ?? null,
      addToContacts: false,
    };
  });
  protected readonly receiverChanges = unsavedChanges(this.receiver, this.savedReceiver);

  /** The saved version the note stands on, and what another person's save changed in it. */
  protected readonly sync = liveRecord({
    kind: 'delivery_note',
    id: this.id,
    form: this.form,
    reload: async () => {
      const companyId = this.company()?.id;
      const id = this.id();
      if (companyId && id !== null) await this.facade.loadNote(companyId, id);
    },
    saved: () => {
      const current = this.current();
      const options = this.options();
      return current && options ? deliveryNoteValues(current, options) : null;
    },
    version: () => this.current()?.version ?? null,
    // The lines are one field: a line is never merged with another person's; so is who receives.
    parts: [
      {
        field: 'receivedBy',
        saved: () => {
          const saved = this.savedReceiver();
          return saved === null ? null : JSON.stringify([saved.name, saved.contactId]);
        },
        shown: () => {
          const shown = this.receiver()?.getRawValue();
          return JSON.stringify([shown?.name ?? '', shown?.contactId ?? null]);
        },
        take: () => this.showSavedReceiver(),
      },
      {
        field: 'lines',
        saved: () => {
          const current = this.current();
          const options = this.options();
          return current && options
            ? JSON.stringify(linesArray(current.lines, options).getRawValue())
            : null;
        },
        shown: () => JSON.stringify(this.lines()?.getRawValue() ?? []),
        take: () => this.linesVersion.update((version) => version + 1),
      },
    ],
  });

  /** Who the note is for, as the picker answered it: the row itself, so the taxes its regime refuses are known. */
  protected readonly customer = signal<CustomerOption | null>(null);

  /**
   * What is typed and not saved, counted so that leaving the page asks first (row 45, RCH-01): the header's fields,
   * the lines, and who the note is for. Each part declares itself to the leave guard.
   */
  private readonly savedHeader = computed(() => {
    const current = this.current();
    const options = this.options();
    return current === undefined || options === null ? null : deliveryNoteValues(current, options);
  });
  protected readonly headerChanges = unsavedChanges(this.form, this.savedHeader);
  private readonly savedLines = computed(() => {
    const current = this.current();
    const options = this.options();
    if (current === undefined || options === null) return null;
    return linesArray(current?.lines ?? [], options).getRawValue();
  });
  protected readonly lineChanges = unsavedChanges(this.lines, this.savedLines);
  private readonly customerChanges = computed(() => {
    const current = this.current();
    const picked = this.customer()?.id ?? null;
    if (current === undefined || picked === null) return 0;
    return picked !== (current?.customerId ?? null) ? 1 : 0;
  });
  protected readonly customerShown = computed(() => pickedCustomer(this.customer()));
  /**
   * Where the customer's goods go, offered beside the note's own address so one part is corrected rather than all
   * typed; nothing is offered for a customer whose record holds no address.
   */
  protected readonly customerAddress = computed(() => {
    const address = this.customer()?.deliveryAddress ?? null;
    return holdsAddress(address, DELIVERY_ADDRESS_FIELDS) ? address : null;
  });

  /** Copies the customer's address into the note's, every part, each still editable and unsaved until saved. */
  protected copyCustomerAddress(): void {
    const form = this.form();
    const address = this.customerAddress();
    if (form === null || address === null) return;
    fillAddress(form, DELIVERY_ADDRESS_FIELDS, address);
  }

  /** Bumped by every value the form or the lines take, so the figures read what is typed now. */
  private readonly typed = signal(0);
  /** The establishment the note is typed at, whose shelves its lines read; null for the main one. */
  protected readonly typedEstablishment = computed(() => {
    this.typed();
    const value = this.form()?.getRawValue()['establishmentId'];
    return typeof value === 'string' && value !== '' ? value : null;
  });
  /** Whether the lines say what is on hand: for whoever may read stock, while the company keeps stock. */
  protected readonly showsStock = computed(
    () => this.auth.hasModule('inventory') && this.auth.hasPermission('stock.read'),
  );
  /**
   * What the figures are asked for: the note as it would be saved, with the lines that can be worked out yet. A note
   * that no longer changes, or that is not for anyone yet, asks nothing.
   */
  private readonly previewDraft = computed(() => {
    this.typed();
    const companyId = this.company()?.id;
    const id = this.id();
    const form = this.form();
    const lines = this.lines();
    const customerId = this.customer()?.id ?? '';
    if (!this.editable() || !companyId || form === null || lines === null || customerId === '')
      return null;
    return untracked(() => {
      const input = deliveryNoteInput(form.getRawValue(), lines, customerId);
      const positions = lines.controls.flatMap((line, index) =>
        figuresReady(line) ? [index] : [],
      );
      const sent = positions.map((index) => {
        const line = input.lines[index]!;
        // Its words and its lot are no figures: one not described yet is worked out, and a lot is checked on save.
        return {
          ...line,
          description: line.description === '' ? '…' : line.description,
          lotCode: null,
        };
      });
      return { companyId, id, input: { ...input, lines: sent }, positions, count: lines.length };
    });
  });
  /** The figures as the note stands typed, from the API's one calculator, or null until it has answered. */
  protected readonly figures = liveFigures(
    () => this.previewDraft(),
    async ({ companyId, id, input, positions, count }) => {
      const body = await this.facade.preview(companyId, id, input);
      return body === null ? null : toDocumentFigures(body, positions, count);
    },
  );
  protected readonly lineFigures = computed(() => this.figures()?.lines ?? null);
  /** What the totals card shows: the figures as typed while the draft is edited, otherwise the note as saved. */
  protected readonly shownTotals = computed(() => {
    const live = this.editable() ? this.figures() : null;
    if (live !== null) return { ...live, live: true };
    const current = this.current();
    return current ? { ...current, live: false } : null;
  });
  /**
   * Whom a locked note is for, as it recorded them: the customer is a picker beside the form, not a field of its
   * descriptor, so the read view would otherwise never name them.
   */
  protected readonly customerOfRecord = computed(
    () => this.current()?.recordedCustomerName ?? this.customerShown()?.name ?? '',
  );
  /** The customer, read first inside « Client et établissement » once the document no longer changes. */
  protected readonly viewLead = computed<RecordLead>(() => ({
    section: 'parties',
    label: 'delivery_notes.fields.customerId',
    value: this.customerOfRecord(),
    testId: 'delivery-note-view-customer',
  }));
  /** The note read rather than filled in, once it no longer changes (design review finding 3). */
  protected readonly asView = computed(() => !this.editable() && this.current() != null);
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
  protected readonly searchCustomers = async (words: string): Promise<readonly PickOption[]> => {
    const companyId = this.company()?.id;
    if (!companyId) return [];
    const found = await this.facade.pickCustomers(companyId, { words });
    for (const customer of found) this.knownCustomers.set(customer.id, customer);
    return found.map((customer) => pickedCustomer(customer) as PickOption);
  };
  protected readonly excludedFamilies = computed<readonly TaxFamily[]>(
    () => this.customer()?.excludedFamilies ?? [],
  );

  /** What the record will offer once its planned modules ship, from the moment it exists. */
  protected readonly planned = computed(() => (this.current() ? DELIVERY_NOTE_PLANNED : []));
  /**
   * What the note offers, declared once for the bar beside its title (design review finding 3). The state's next
   * step is the primary one: validating a draft, delivering a validated note, invoicing a delivered one. Delivering
   * asks for its day in a dialog rather than keeping a date field in the bar, and cancelling asks before it runs.
   */
  protected readonly actions = computed<ScreenAction[]>(() => {
    const busy = this.busy();
    return [
      {
        id: 'save',
        shortcut: 's',
        label: 'delivery_notes.actions.save',
        icon: 'save',
        // Kept in a phone's bar, or a new document there offers no way to save it.
        keep: true,
        disabled: busy,
        run: () => void this.save(),
        shown: this.editable() && this.form() !== null,
      },
      {
        id: 'validate',
        shortcut: 'v',
        next: true,
        label: 'delivery_notes.actions.validate',
        icon: 'check',
        primary: true,
        disabled: busy,
        run: () => void this.validate(),
        shown: this.canValidate(),
        // Numbering is for good, so neither a click nor the bare key validates unasked (EFF-01, row 45).
        confirm: {
          kind: 'corrigeable',
          title: 'delivery_notes.actions.validate_title',
          message: 'delivery_notes.actions.validate_message',
          confirmLabel: 'delivery_notes.actions.confirm_validate',
          keepLabel: 'delivery_notes.actions.keep',
        },
      },
      {
        id: 'validate-deliver',
        label: 'delivery_notes.actions.validate_deliver',
        icon: 'local_shipping',
        disabled: busy,
        run: () => void this.validateAndDeliver(),
        shown: this.canValidate(),
        confirm: {
          kind: 'corrigeable',
          title: 'delivery_notes.actions.validate_deliver_title',
          message: 'delivery_notes.actions.validate_deliver_message',
          confirmLabel: 'delivery_notes.actions.validate_deliver',
          keepLabel: 'delivery_notes.actions.keep',
        },
      },
      {
        id: 'deliver',
        shortcut: 'l',
        next: true,
        label: 'delivery_notes.actions.deliver',
        icon: 'local_shipping',
        primary: true,
        disabled: busy,
        run: () => void this.openDeliver(),
        shown: this.canDeliver(),
      },
      {
        id: 'invoice',
        label: 'delivery_notes.actions.invoice',
        next: true,
        icon: 'receipt_long',
        primary: true,
        disabled: busy,
        run: () => void this.invoice(),
        shown: this.canInvoice(),
      },
      {
        id: 'invoice-part',
        label: 'delivery_notes.actions.invoice_part',
        icon: 'tune',
        disabled: busy,
        run: () => void this.invoicePart(),
        shown: this.canInvoice(),
      },
      {
        id: 'pdf',
        label:
          this.current()?.status === 'draft'
            ? 'delivery_notes.actions.pdf_draft'
            : 'delivery_notes.actions.pdf',
        icon: 'picture_as_pdf',
        href: this.pdfUrl() ?? undefined,
        shown: this.pdfUrl() !== null,
      },
      {
        id: 'pdf-delivered',
        label: 'delivery_notes.actions.pdf_delivered',
        icon: 'picture_as_pdf',
        rare: true,
        href: this.deliveredCopyUrl() ?? undefined,
        shown: this.deliveredCopyUrl() !== null,
      },
      {
        id: 'correct-receiver',
        label: 'delivery_notes.actions.correct_receiver',
        icon: 'person_edit',
        rare: true,
        disabled: busy,
        run: () => void this.openCorrectReceiver(),
        shown: this.canCorrectReceiver(),
      },
      {
        id: 'on-map',
        label: 'delivery_notes.actions.on_map',
        icon: 'warehouse',
        run: () => void this.openOnMap(),
        shown: this.mapProductIds().length > 0,
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
        id: 'cancel',
        label: 'delivery_notes.actions.cancel',
        icon: 'block',
        destructive: true,
        disabled: busy,
        run: () => void this.cancel(),
        shown: this.canCancel(),
        confirm: {
          kind: 'definitif',
          title: 'delivery_notes.actions.cancel_title',
          message: 'delivery_notes.actions.cancel_message',
          confirmLabel: 'delivery_notes.actions.confirm_cancel',
          keepLabel: 'delivery_notes.actions.keep',
        },
      },
      {
        id: 'delete',
        label: 'records.delete',
        icon: 'delete',
        // Asks nothing: the toast offers « Annuler », and « Récemment supprimés » keeps the draft a day. A draft another
        // record names is refused, the toast saying by what.
        destructive: true,
        undoable: true,
        disabled: busy,
        run: () => void this.remove(),
        shown: this.current()?.status === 'draft' && this.mayWrite(),
      },
    ];
  });

  protected readonly pdfUrl = computed(() => {
    const companyId = this.company()?.id;
    const current = this.current();
    return companyId && current ? this.facade.pdfUrl(companyId, current.id) : null;
  });
  /** The copy saying who received a note delivered since, the original kept as it was issued. */
  protected readonly deliveredCopyUrl = computed(() => {
    const companyId = this.company()?.id;
    const current = this.current();
    return companyId && current?.deliveredAt
      ? this.facade.deliveredCopyUrl(companyId, current.id)
      : null;
  });
  /** Who received is put right on a recorded note, unless its customer's notes name nobody and it names nobody. */
  protected readonly canCorrectReceiver = computed(() => {
    const current = this.current();
    return (
      current !== null &&
      current !== undefined &&
      current.status !== 'draft' &&
      current.status !== 'cancelled' &&
      this.mayWrite() &&
      (current.receiverRule !== 'off' || current.receivedBy !== null)
    );
  });
  protected readonly canValidate = computed(
    () => this.current()?.status === 'draft' && this.mayWrite() && this.mayValidate(),
  );
  protected readonly canDeliver = computed(
    () => this.current()?.status === 'validated' && this.mayWrite(),
  );
  /** A validated or delivered note becomes an invoice, while the invoices module is on for a writer of invoices. */
  protected readonly canInvoice = computed(() => this.invoiceable(this.current()));
  /** Whether the note hands over an issued invoice's goods, which it says: it moves no stock and is never invoiced. */
  protected readonly deliversInvoice = computed(() => deliversInvoice(this.current()));
  /**
   * The products the saved note names, each once and in its order, which the stock map lights to say where to pick
   * them; none where the stock is not kept or not readable here, so the action is offered only where it can answer,
   * and none once the note is delivered or cancelled, when nothing is left to pick.
   */
  protected readonly mapProductIds = computed(() => {
    if (!this.auth.hasModule('inventory') || !this.auth.hasPermission('stock.read')) return [];
    const status = this.current()?.status;
    if (status !== 'draft' && status !== 'validated') return [];
    const ids = (this.current()?.lines ?? []).flatMap((line) =>
      line.productId === null ? [] : [line.productId],
    );
    return [...new Set(ids)];
  });
  /** Deletes the draft, then leaves its page for the list once it is gone; what was typed in it goes with it. */
  private async remove(): Promise<void> {
    const companyId = this.company()?.id;
    const current = this.current();
    if (!companyId || !current) return;
    const gone = await this.deletes.remove(companyId, 'delivery_note', [
      { id: current.id, label: current.number ?? current.customerName },
    ]);
    if (!gone) return;
    this.unsaved.savedAndLeaving();
    await this.router.navigate(['/delivery-notes']);
  }

  protected readonly canCancel = computed(() => {
    const status = this.current()?.status;
    return (status === 'draft' || status === 'validated') && this.mayValidate();
  });

  protected async openOnMap(): Promise<void> {
    await this.router.navigate(['/stock/plan'], {
      queryParams: {
        products: this.mapProductIds().join(','),
        note: this.current()?.number ?? null,
      },
    });
  }

  /**
   * A scan on a draft puts its product on the lines as a till does (docs/SPEC.md § 7, 2026-09-23 09:30), the same
   * rule the invoice follows. Anywhere else the card of what it names takes the scan.
   */
  /** The products « Ajouter à… » sent here, put on the lines as scans would be: priced and taxed as a note's line is. */
  private async takeIn(asked: readonly string[]): Promise<TakenIn | null> {
    const lines = this.lines();
    const options = this.options();
    const companyId = this.company()?.id;
    if (lines === null || options === null || !companyId) return null;
    const found = await this.facade.pickProducts(companyId, { ids: [...asked] });
    const excluded = this.excludedFamilies();
    return productsIntoLines<LineGroup, ProductOption>(
      lines,
      (line) => line.controls,
      asked,
      found,
      (product) => {
        const line = lineGroup(null, options);
        applyProduct(line, product, options, excluded);
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

    const excluded = this.excludedFamilies();
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
        const line = lineGroup(null, options);
        applyProduct(line, product, options, excluded);
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

  /** The contacts « Reçu par » offers for this customer; an answer for a customer the draft no longer names is dropped. */
  private async readReceiverContacts(companyId: string, customerId: string): Promise<void> {
    const contacts = await this.facade.receivers(companyId, customerId);
    if (this.customer()?.id === customerId) this.receiverContacts.set(contacts);
  }

  constructor() {
    this.unsaved.declare(this.customerChanges);
    // « Reçu par » offers the contacts of the customer the draft names now.
    effect(() => {
      const companyId = this.company()?.id;
      const customerId = this.customer()?.id;
      untracked(() => this.receiverContacts.set([]));
      if (!companyId || !customerId || !this.receiverAsked()) return;
      untracked(() => void this.readReceiverContacts(companyId, customerId));
    });
    // A contact added meanwhile, by a colleague or on the customer's page, is offered without leaving the draft:
    // unseen, it was typed again, ticked « Ajouter aux contacts », and the customer held the person twice.
    inject(LiveChanges).reloadOn(
      ['contact'],
      async () => {
        const companyId = this.company()?.id;
        const customerId = this.customer()?.id;
        if (companyId && customerId && this.receiverAsked()) {
          await this.readReceiverContacts(companyId, customerId);
        }
      },
      inject(DestroyRef),
    );
    // The same list the bar draws also answers the keyboard, the palette and the "?" sheet (row 45): one
    // declaration, so an action cannot be offered in one of them and missing from another.
    inject(ScreenActions).declare(this.actions);
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
    const scans = inject(ScanBus);
    scans.handle((scan) => this.scanned(scan));
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
    // Another note on the same screen is another sale: what the display showed was the last one's.
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
    effect(() => {
      const companyId = this.company()?.id;
      const id = this.id();
      untracked(() => {
        this.confirmingCancel.set(false);
        if (companyId) {
          void this.facade.loadNote(companyId, id);
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
    // An open note names its customer by id alone; the picker is shown the row that id resolves to.
    effect(() => {
      const companyId = this.company()?.id;
      const current = this.current();
      if (!companyId || current === undefined || current === null) return;
      const customerId = current.customerId;
      untracked(async () => {
        if (this.customer()?.id === customerId) return;
        const known = this.knownCustomers.get(customerId);
        const [found] = known
          ? [known]
          : await this.facade.pickCustomers(companyId, { ids: [customerId] });
        if (found) this.knownCustomers.set(found.id, found);
        this.customer.set(found ?? null);
      });
    });
  }

  /** The taxes a line offers follow the customer named in the header. */
  protected chooseCustomer(option: PickOption | null): void {
    const customer = option === null ? null : (this.knownCustomers.get(option.id) ?? null);
    // A contact is the customer's: named for another, it stays as a name typed.
    if (customer?.id !== this.customer()?.id) this.receiver()?.controls.contactId.setValue(null);
    this.customer.set(customer);
    this.customerMissing.set(customer === null);
    this.customerRefused.set(null);
  }

  /** Shows who the saved note names, in place of what the field holds. */
  private showSavedReceiver(): void {
    const saved = this.savedReceiver();
    if (saved !== null) this.receiver()?.reset(saved);
  }

  protected async save(): Promise<void> {
    const companyId = this.company()?.id;
    const input = this.collect();
    if (!companyId || input === null) return;
    const id = this.id();
    if (id === null) {
      const created = await this.facade.create(companyId, input);
      if (created !== null) {
        this.feedback.success('delivery_notes.saved');
        // It exists now: going to it is not leaving unsaved work, though the form still holds what was
        // typed and the record holds what the API answered (row 45's leave guard, 2026-09-20).
        this.unsaved.savedAndLeaving();
        this.handedOver = true;
        await this.router.navigate(['/delivery-notes', created.id], { replaceUrl: true });
      } else {
        await this.refused();
      }
    } else if ((await this.facade.revise(companyId, id, input, this.sync.version())) !== null) {
      this.revisedHere();
      this.feedback.success('delivery_notes.saved');
    } else {
      await this.refused();
    }
  }

  /** The form now stands on what this tab saved, before anything else is asked of the note. */
  private readonly revisedHere = (): void => {
    const form = this.form();
    if (form !== null) this.sync.savedHere(form);
    this.showSavedReceiver();
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
   * Each field the API refused, said under it: a line's on its line, the customer under its picker, the rest of the
   * header in its form. The line above the form stays for what is refused as a whole.
   */
  private showRefused(refusals: readonly FieldRefusal[]): void {
    if (refusals.length === 0) return;
    const rest = this.linesEditor()?.showRefused(refusals) ?? refusals;
    const customer = rest.find((each) => each.field === 'customerId');
    this.customerRefused.set(
      customer === undefined
        ? null
        : refusedError(this.translate, customer, customer.params, this.refusalFigures),
    );
    const receiver = this.receiver();
    const receiverRefused = rest.find((each) => each.field === 'receivedBy');
    const receiverPlaced =
      receiverRefused !== undefined && receiver !== null && this.receiverAsked();
    if (receiverPlaced) {
      receiver.controls.name.setErrors({
        refused: refusedError(
          this.translate,
          receiverRefused,
          receiverRefused.params,
          this.refusalFigures,
        ),
      });
      receiver.controls.name.markAsTouched();
    }
    const header = rest.filter(
      (each) => each.field !== 'customerId' && !(receiverPlaced && each.field === 'receivedBy'),
    );
    const unplaced = header.length > 0 ? (this.headerEditor()?.showRefused(header) ?? header) : [];
    this.refusalLine.placed(refusals, unplaced);
    // Alone refused, « Reçu par » takes the cursor as the first wrong field of a form does: the bar pressed may lie
    // far below it, and nothing else on screen would say why the step did not happen.
    if (receiverPlaced && header.length === 0 && rest.length === refusals.length) {
      this.host.nativeElement.querySelector<HTMLElement>('app-receiver-field input')?.focus();
    }
  }

  /** A note handing over an issued invoice's goods is never invoiced: that invoice billed them already. */
  private invoiceable(note: Pick<DeliveryNoteRow, 'status' | 'lines'> | null | undefined): boolean {
    const status = note?.status;
    return (
      (status === 'validated' || status === 'delivered') &&
      !deliversInvoice(note) &&
      this.auth.hasModule('invoices') &&
      this.auth.hasPermission('invoice.write')
    );
  }

  protected async validate(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    const input = this.collect();
    if (!companyId || id === null || input === null) return;
    const kind = kindAmong(this.actions(), 'validate');
    const validated = await this.facade.reviseAndValidate(
      companyId,
      id,
      input,
      this.sync.version(),
      this.revisedHere,
    );
    if (validated === null) await this.refused();
    if (validated === null || kind === undefined) return;
    // Its next step, to whoever may take it (docs/SPEC.md § 7, 2026-09-26, row 139).
    const next = this.invoiceable(validated)
      ? { key: 'delivery_notes.suggest.invoice', run: () => void this.invoice() }
      : undefined;
    this.feedback.effect('delivery_notes.validated', {}, kind, next);
  }

  protected async validateAndDeliver(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    const input = this.collect();
    if (!companyId || id === null || input === null) return;
    const kind = kindAmong(this.actions(), 'validate-deliver');
    const delivered = await this.facade.reviseValidateAndDeliver(
      companyId,
      id,
      input,
      this.sync.version(),
      this.revisedHere,
    );
    if (delivered === null) await this.refused();
    if (delivered === null || kind === undefined) return;
    const next = this.invoiceable(delivered)
      ? { key: 'delivery_notes.suggest.invoice', run: () => void this.invoice() }
      : undefined;
    this.feedback.effect('delivery_notes.validated_delivered', {}, kind, next);
  }

  /** Asked in a dialog, so the bar carries actions and not a date field (design review finding 3). */
  protected async openDeliver(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    const current = this.current();
    if (!companyId || id === null || !current || this.busy()) return;
    await firstValueFrom(
      this.dialog
        .open<DeliverDialog, DeliverDialogData, boolean>(DeliverDialog, {
          data: {
            zone: this.company()?.timezone ?? null,
            // At the moment written on the company's clock, or now for ''.
            receiver: this.deliverReceiver(current),
            dropped:
              current.receiverRule === 'off' ? (current.receivedBy?.name ?? undefined) : undefined,
            deliver: (at, receivedBy) =>
              this.facade.deliver(companyId, id, at === '' ? null : at, receivedBy),
          },
          autoFocus: 'first-tabbable',
        })
        .afterClosed(),
    );
  }
  /** What the deliver dialog asks of who received: nothing where the customer's notes name nobody. */
  private deliverReceiver(note: DeliveryNoteRow): DeliverDialogData['receiver'] {
    const companyId = this.company()?.id;
    if (note.receiverRule === 'off' || !companyId) return undefined;
    return {
      current: note.receivedBy,
      required: note.receiverRule === 'required',
      contacts: this.facade.receivers(companyId, note.customerId),
    };
  }

  /** « Reçu par » put right once the note is recorded; the original keeps whom it was issued naming. */
  protected async openCorrectReceiver(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    const current = this.current();
    if (!companyId || id === null || !current || this.busy()) return;
    const before = current.receivedBy;
    const saved = await firstValueFrom(
      this.dialog
        .open<ReceiverDialog, ReceiverDialogData, boolean>(ReceiverDialog, {
          data: {
            title: 'delivery_notes.actions.correct_receiver',
            current: current.receivedBy,
            required: current.receiverRule === 'required',
            contacts: this.facade.receivers(companyId, current.customerId),
            save: (receivedBy) => this.facade.correctReceiver(companyId, id, receivedBy),
          },
          autoFocus: 'first-tabbable',
        })
        .afterClosed(),
    );
    if (saved === true) this.saidCorrected(companyId, id, before);
  }

  /**
   * Said once put right; « Annuler » names again whoever was named before: the same contact, the same name typed, or
   * nobody.
   */
  private saidCorrected(companyId: string, id: string, before: Receiver | null): void {
    const shown = this.current()?.receivedBy ?? null;
    this.feedback.effect('delivery_notes.receiver_corrected', {}, 'corrigeable', {
      key: 'receivers.undo',
      run: () =>
        void this.facade
          .correctReceiver(
            companyId,
            id,
            before === null
              ? null
              : before.contactId !== null
                ? { contactId: before.contactId, name: null, addToContacts: false }
                : { contactId: null, name: before.name, addToContacts: false },
          )
          .then((answer) => {
            if (answer === null) return;
            if ('kept' in answer) this.saidCorrected(companyId, id, shown);
            else
              sayReceiverUndoRefused(
                this.feedback,
                this.translate,
                this.refusalFigures,
                answer.refused,
              );
          }),
    });
  }

  protected async invoice(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (!companyId || id === null || this.busy()) return;
    const target = await this.chooseTarget(companyId);
    if (target === null) return;
    const invoiceId = await this.facade.invoice(
      companyId,
      id,
      undefined,
      target.draftId ?? undefined,
    );
    if (invoiceId !== null) {
      await this.router.navigate(['/invoices', invoiceId]);
    }
  }

  /**
   * Where the note is invoiced: asked only when the customer has a draft in the note's establishment to add it to,
   * otherwise it starts a new invoice as it always did. Null when the person left the question.
   */
  private async chooseTarget(companyId: string): Promise<InvoiceTarget | null> {
    const current = this.current();
    if (!current || current.customerId === null) return { draftId: null };
    const drafts = await this.facade.draftsOf(
      companyId,
      current.customerId,
      current.establishmentId,
    );
    // A lookup that failed is said in `error`; the note then still starts a new invoice rather than going nowhere.
    if (drafts === null || drafts.length === 0) return { draftId: null };
    return askInvoiceTarget(
      this.dialog,
      NOTE_INVOICE_TARGET,
      drafts,
      current.customerId,
      this.scale() ?? 3,
    );
  }

  /** An invoice for part of the note: how much of each line is asked in a dialog, from what is left of it. */
  protected async invoicePart(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    const current = this.current();
    if (!companyId || id === null || !current || this.busy()) return;
    const left = await this.facade.left(companyId, id);
    if (left === null) return;
    const lines: InvoicePartLine[] = left.lines.map((line, index) => ({
      ...line,
      description: current.lines[index]?.description ?? '',
    }));
    // Where it goes is asked over the dialog, which stays as it was when the person leaves that question.
    const invoice = async (quantities: Record<string, string>): Promise<FormAnswer<string>> => {
      const target = await this.chooseTarget(companyId);
      if (target === null) return { refused: [] };
      return this.facade.invoicePart(companyId, id, quantities, target.draftId ?? undefined);
    };
    const invoiceId = await firstValueFrom(
      this.dialog
        .open<InvoicePartDialog, InvoicePartData, string | null>(InvoicePartDialog, {
          data: { lines, invoice },
          autoFocus: 'first-tabbable',
        })
        .afterClosed(),
    );
    if (invoiceId) await this.router.navigate(['/invoices', invoiceId]);
  }

  protected async cancel(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    this.confirmingCancel.set(false);
    if (!companyId || id === null || this.busy()) return;
    const kind = kindAmong(this.actions(), 'cancel');
    const cancelled = await this.facade.cancel(companyId, id);
    if (cancelled !== null && kind !== undefined) {
      this.feedback.effect('delivery_notes.cancelled', {}, kind);
    }
  }

  /** The header and the lines as the API takes them, or null after showing what is wrong with them. */
  private collect(): DeliveryNoteInput | null {
    const form = this.form();
    const lines = this.lines();
    if (form === null || lines === null || this.busy()) return null;
    const customerId = this.customer()?.id ?? '';
    this.customerMissing.set(customerId === '');
    const receiver = this.receiverAsked() ? this.receiver() : null;
    if (form.invalid || lines.invalid || customerId === '' || receiver?.invalid === true) {
      form.markAllAsTouched();
      lines.markAllAsTouched();
      receiver?.markAllAsTouched();
      return null;
    }
    return deliveryNoteInput(
      form.getRawValue(),
      lines,
      customerId,
      receiver === null ? null : receiverChoice(receiver),
    );
  }
}
