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
import { MatDialog } from '@angular/material/dialog';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { firstValueFrom } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { InvoiceLines } from '../invoices/invoice-lines';
import { figuresReady, linesArray, pickedCustomer } from '../invoices/invoice-forms';
import { liveFigures, negated, toDocumentFigures } from '../shared/documents/document-figures';
import type { CustomerOption } from '../invoices/invoices-types';
import { LineCatalogue } from '../invoices/line-catalogue';
import { taxNames } from '../invoices/tax-names';
import type { PlannedAction } from '../shared/actions/planned-actions';
import { kindAmong, type ScreenAction } from '../shared/actions/screen-action';
import { ScreenActions } from '../shared/actions/screen-actions';
import { Feedback } from '../shared/feedback/feedback';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { unsavedChanges } from '../shared/form/dirty-count';
import { FileDrop } from '../shared/form/file-drop';
import { ATTACHMENT_MAX_BYTES } from '../shared/form/file-limits';
import { buildFormGroup } from '../shared/form/form-builder';
import { liveRecord } from '../shared/form/live-record';
import { PartConflict } from '../shared/form/part-conflict';
import { PickField, type PickOption } from '../shared/form/pick-field';
import { RecordChanged } from '../shared/form/record-changed';
import { RecordView } from '../shared/form/record-view';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { AmountPipe, DayPipe, MomentPipe } from '../shared/i18n/format-pipes';
import { DocumentActions } from '../shared/ui/document-actions';
import { StatusBadge } from '../shared/ui/status-badge';
import { QuoteAnswerDialog, type QuoteAnswered } from './quote-answer-dialog';
import { QuoteDepositDialog, type QuoteDepositDialogData } from './quote-deposit-dialog';
import { quoteForm, quoteInput, quoteValues, shownStatus } from './quote-forms';
import { QuotesFacade } from './quotes-facade';
import {
  QUOTE_STATUS_STAGES,
  QUOTE_STATUS_TONES,
  type DepositShare,
  type QuoteAttachment,
  type QuoteDeposit,
  type QuoteInput,
} from './quotes-types';

/** What a quote will offer once its planned modules ship, shown « Bientôt » beside what it offers today. */
export const QUOTE_PLANNED: readonly PlannedAction[] = [
  { module: 'mailing', label: 'planned_actions.send_email', icon: 'forward_to_inbox' },
  { module: 'whatsapp', label: 'planned_actions.send_whatsapp', icon: 'chat' },
];

/**
 * One quote: a new draft to fill in, a draft to revise, mark sent or cancel, then the customer's answer to record and
 * an accepted quote to invoice. Only a draft changes; the API refuses anything else whatever this screen shows, and
 * works out every figure it shows. The lines are the invoice's editor, asking the catalogue under the quote's own
 * permission through `LineCatalogue`.
 */
@Component({
  selector: 'app-quote-page',
  imports: [
    MatButtonModule,
    MatCardModule,
    RouterLink,
    TranslatePipe,
    AmountPipe,
    DayPipe,
    MomentPipe,
    DescriptorForm,
    DocumentActions,
    FileDrop,
    InvoiceLines,
    PartConflict,
    PickField,
    RecordChanged,
    RecordView,
    StatusBadge,
  ],
  providers: [{ provide: LineCatalogue, useExisting: QuotesFacade }],
  templateUrl: './quote-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class QuotePage {
  private readonly facade = inject(QuotesFacade);
  private readonly unsaved = inject(UnsavedChanges);
  private readonly dialog = inject(MatDialog);
  private readonly feedback = inject(Feedback);
  protected readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);

  /** Bound from the route parameter by withComponentInputBinding(); absent on `quotes/new`. */
  readonly quoteId = input<string | undefined>(undefined);
  /** `?customerId=` on a new quote: the customer « Nouveau devis » on a customer's page names, chosen once as if picked. */
  readonly customerId = input<string | undefined>(undefined);

  protected readonly id = computed(() => this.quoteId() ?? null);
  protected readonly options = this.facade.options;
  protected readonly taxName = computed(() => taxNames(this.options()?.taxes ?? []));
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly attachments = this.facade.attachments;
  protected readonly maxBytes = ATTACHMENT_MAX_BYTES;
  protected readonly scale = computed(() => this.options()?.currencyScale ?? null);
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('quote.write'));
  /** « Facturer » drafts an invoice, which takes writing invoices and the invoices module on, as the API asks. */
  protected readonly mayInvoice = computed(
    () => this.auth.hasPermission('invoice.write') && this.auth.hasModule('invoices'),
  );
  protected readonly mayReadInvoices = computed(
    () => this.auth.hasPermission('invoice.read') && this.auth.hasModule('invoices'),
  );

  /** Null while a new quote is filled in; undefined until the quote asked for has been read. */
  protected readonly current = computed(() => {
    const id = this.id();
    if (id === null) return null;
    const quote = this.facade.quote();
    return quote?.id === id ? quote : undefined;
  });
  protected readonly shown = computed(() => {
    const current = this.current();
    return current ? shownStatus(current) : null;
  });
  protected readonly tones = QUOTE_STATUS_TONES;
  protected readonly stages = QUOTE_STATUS_STAGES;
  protected readonly editable = computed(() => {
    const current = this.current();
    return this.mayWrite() && (current === null || current?.status === 'draft');
  });
  protected readonly descriptor = computed(() => {
    const options = this.options();
    const current = this.current();
    if (options === null || current === undefined) return null;
    return quoteForm(options, current);
  });
  /**
   * What the form is of: the quote, its state and the fields shown. Reading the quote again yields new objects with
   * the same content, and must not rebuild the form over what is being typed; another quote or state must.
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
      return buildFormGroup(descriptor, quoteValues(current, options));
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
  /** The saved version the quote stands on, and what another person's save changed in it. */
  protected readonly sync = liveRecord({
    kind: 'quote',
    id: this.id,
    form: this.form,
    reload: async () => {
      const companyId = this.company()?.id;
      const id = this.id();
      if (companyId && id !== null) await this.facade.loadQuote(companyId, id);
    },
    saved: () => {
      const current = this.current();
      const options = this.options();
      return current && options ? quoteValues(current, options) : null;
    },
    // The lines are one field: a line is never merged with another person's.
    parts: [
      {
        field: 'lines',
        saved: () => {
          const current = this.current();
          const options = this.options();
          if (!current || options === null) return null;
          return JSON.stringify(linesArray(current.lines, options, this.customer()).getRawValue());
        },
        shown: () => JSON.stringify(this.lines()?.getRawValue() ?? []),
        take: () => this.linesVersion.update((version) => version + 1),
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
  protected readonly showsStock = computed(
    () => this.auth.hasModule('inventory') && this.auth.hasPermission('stock.read'),
  );
  /**
   * What the figures are asked for: the quote as it would be saved, with the lines that can be worked out yet. A quote
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
      const input = quoteInput(form.getRawValue(), lines, customerId);
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
  /** The figures as the quote stands typed, from the API's one calculator, or null until it has answered. */
  protected readonly figures = liveFigures(
    () => this.previewDraft(),
    async ({ companyId, id, input, positions, count }) => {
      const body = await this.facade.preview(companyId, id, input);
      return body === null ? null : toDocumentFigures(body, positions, count);
    },
  );
  protected readonly lineFigures = computed(() => this.figures()?.lines ?? null);
  protected readonly negated = negated;
  /** What the totals card shows: the figures as typed while the draft is edited, otherwise the quote as saved. */
  protected readonly shownTotals = computed(() => {
    const live = this.editable() ? this.figures() : null;
    if (live !== null) return { ...live, live: true };
    const current = this.current();
    return current ? { ...current, live: false } : null;
  });

  /** What is typed and not saved, counted so that leaving the page asks first. */
  private readonly savedHeader = computed(() => {
    const current = this.current();
    const options = this.options();
    return current === undefined || options === null ? null : quoteValues(current, options);
  });
  protected readonly headerChanges = unsavedChanges(this.form, this.savedHeader);
  private readonly savedLines = computed(() => {
    const current = this.current();
    const options = this.options();
    if (current === undefined || options === null) return null;
    return linesArray(current?.lines ?? [], options, this.customer()).getRawValue();
  });
  protected readonly lineChanges = unsavedChanges(this.lines, this.savedLines);
  /** Picking someone else, or anyone at all on a new quote. */
  private readonly customerChanges = computed(() => {
    const current = this.current();
    const picked = this.customer()?.id ?? null;
    if (current === undefined || picked === null) return 0;
    return picked !== (current?.customerId ?? null) ? 1 : 0;
  });

  /** Who the quote is for, as the picker answered it: the whole row, with what its regime refuses and its discount. */
  protected readonly customer = signal<CustomerOption | null>(null);
  protected readonly customerShown = computed(() => pickedCustomer(this.customer()));
  /** The quote read rather than filled in, once it no longer changes. */
  protected readonly asView = computed(() => !this.editable() && this.current() != null);
  protected readonly customerOfRecord = computed(
    () => this.current()?.recordedCustomerName ?? this.customerShown()?.name ?? '',
  );
  protected readonly viewPicked = computed<Record<string, string>>(() => {
    const customer = this.customerShown();
    const picked: Record<string, string> = {};
    if (customer !== null) picked['customerId'] = customer.name;
    return picked;
  });
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

  protected readonly planned = computed(() => (this.current() ? QUOTE_PLANNED : []));
  /**
   * What the quote offers, declared once for the bar beside its title. The state's next step is the primary: marking
   * a draft sent, recording the customer's yes on a sent quote, invoicing an accepted one.
   */
  protected readonly actions = computed<ScreenAction[]>(() => {
    const busy = this.busy();
    const status = this.current()?.status;
    const invoiceId = this.current()?.invoiceId ?? null;
    return [
      {
        id: 'save',
        label: 'quotes.actions.save',
        shortcut: 's',
        icon: 'save',
        disabled: busy,
        run: () => void this.save(),
        shown: this.editable() && this.form() !== null,
      },
      {
        id: 'send',
        next: true,
        label: 'quotes.actions.send',
        icon: 'send',
        primary: true,
        disabled: busy,
        run: () => void this.send(),
        shown: status === 'draft' && this.mayWrite(),
        // Numbering is for good and the content is fixed from then on.
        confirm: {
          kind: 'definitif',
          title: 'quotes.actions.send_title',
          message: 'quotes.actions.send_message',
          confirmLabel: 'quotes.actions.confirm_send',
          keepLabel: 'quotes.actions.keep',
        },
      },
      {
        id: 'accept',
        next: true,
        label: 'quotes.actions.accept',
        icon: 'check_circle',
        primary: true,
        disabled: busy,
        run: () => void this.answer('accept'),
        shown: status === 'sent' && this.mayWrite(),
      },
      {
        id: 'refuse',
        label: 'quotes.actions.refuse',
        icon: 'cancel',
        disabled: busy,
        run: () => void this.answer('refuse'),
        shown: status === 'sent' && this.mayWrite(),
      },
      {
        id: 'invoice',
        next: true,
        label: 'quotes.actions.invoice',
        icon: 'receipt_long',
        primary: true,
        disabled: busy,
        run: () => void this.invoice(),
        shown: status === 'accepted' && invoiceId === null && this.mayWrite() && this.mayInvoice(),
      },
      {
        id: 'deposit',
        label: 'quotes.actions.deposit',
        icon: 'payments',
        // The dialog checks an amount against the currency's decimals, known once the options are read.
        disabled: busy || this.scale() === null,
        run: () => void this.deposit(),
        shown: status === 'accepted' && invoiceId === null && this.mayWrite() && this.mayInvoice(),
      },
      {
        id: 'open-invoice',
        label: 'quotes.actions.open_invoice',
        icon: 'receipt_long',
        link: ['/invoices', invoiceId],
        shown: invoiceId !== null && this.mayReadInvoices(),
      },
      {
        id: 'pdf',
        label: status === 'draft' ? 'quotes.actions.pdf_draft' : 'quotes.actions.pdf',
        icon: 'picture_as_pdf',
        href: this.pdfUrl() ?? undefined,
        shown: this.pdfUrl() !== null,
      },
      {
        id: 'cancel',
        label: 'quotes.actions.cancel',
        icon: 'block',
        destructive: true,
        disabled: busy,
        run: () => void this.cancel(),
        shown: status === 'draft' && this.mayWrite(),
        confirm: {
          kind: 'definitif',
          title: 'quotes.actions.cancel_title',
          message: 'quotes.actions.cancel_message',
          confirmLabel: 'quotes.actions.confirm_cancel',
          keepLabel: 'quotes.actions.keep',
        },
      },
    ];
  });

  protected readonly pdfUrl = computed(() => {
    const companyId = this.company()?.id;
    const current = this.current();
    return companyId && current ? this.facade.pdfUrl(companyId, current.id) : null;
  });

  constructor() {
    this.unsaved.declare(this.customerChanges);
    inject(ScreenActions).declare(this.actions);
    effect(() => {
      const companyId = this.company()?.id;
      const id = this.id();
      untracked(() => {
        if (companyId) void this.facade.loadQuote(companyId, id);
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
    // A saved quote names its customer by id alone; the picker is shown the row that id resolves to.
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
    // Waits for the new quote and its options only, then forgets the customer from the address.
    effect(() => {
      const customerId = this.customerId();
      const companyId = this.company()?.id;
      const ready = this.id() === null && this.current() === null && this.options() !== null;
      if (customerId === undefined || !companyId || !ready) return;
      untracked(async () => {
        void this.router.navigate([], {
          queryParams: { customerId: null },
          queryParamsHandling: 'merge',
          replaceUrl: true,
        });
        const [found] = await this.facade.pickCustomers(companyId, { ids: [customerId] });
        if (found === undefined) return;
        this.knownCustomers.set(found.id, found);
        this.chooseCustomer({ id: found.id, code: found.number, name: found.name });
      });
    });
  }

  /** Naming another customer discounts the lines nobody discounted by hand as that customer's would be. */
  protected chooseCustomer(option: PickOption | null): void {
    const customer = option === null ? null : (this.knownCustomers.get(option.id) ?? null);
    this.customer.set(customer);
    this.customerMissing.set(customer === null);
    if (this.error() === 'customer_unavailable') this.facade.clearError();
    const rate = customer?.defaultDiscountRate ?? '';
    for (const line of this.lines()?.controls ?? []) {
      if (line.controls.discountRate.pristine) line.controls.discountRate.setValue(rate);
    }
  }

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
        this.feedback.success('quotes.saved');
        // It exists now: going to it is not leaving unsaved work.
        this.unsaved.savedAndLeaving();
        await this.router.navigate(['/quotes', created.id], { replaceUrl: true });
      }
    } else if ((await this.facade.revise(companyId, id, input)) !== null) {
      const form = this.form();
      if (form !== null) this.sync.savedHere(form);
      this.feedback.success('quotes.saved');
    }
  }

  protected async send(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    const input = this.collect();
    if (!companyId || id === null || input === null) return;
    // Read before sending: once sent, the screen declares what a sent quote offers.
    const kind = kindAmong(this.actions(), 'send');
    const sent = await this.facade.reviseAndSend(companyId, id, input);
    if (sent !== null && kind !== undefined) {
      this.feedback.effect('quotes.sent', {}, kind);
    }
  }

  /** The customer's answer, asked in a dialog: the day, and the signed copy or why they declined. */
  protected async answer(kind: 'accept' | 'refuse'): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (!companyId || id === null || this.busy()) return;
    const answered = await firstValueFrom(
      this.dialog
        .open<QuoteAnswerDialog, { kind: 'accept' | 'refuse' }, QuoteAnswered | null>(
          QuoteAnswerDialog,
          { data: { kind }, autoFocus: 'first-tabbable' },
        )
        .afterClosed(),
    );
    if (!answered) return;
    if (kind === 'accept') {
      const accepted = await this.facade.accept(
        companyId,
        id,
        { answeredOn: answered.answeredOn },
        answered.signed,
      );
      if (accepted === null) return;
      const next = this.mayInvoice()
        ? { key: 'quotes.suggest.invoice', run: () => void this.invoice() }
        : undefined;
      this.feedback.success('quotes.accepted', {}, next);
    } else {
      const refused = await this.facade.refuse(companyId, id, {
        answeredOn: answered.answeredOn,
        refusalReason: answered.refusalReason,
      });
      if (refused !== null) this.feedback.success('quotes.refused');
    }
  }

  protected async invoice(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (!companyId || id === null || this.busy()) return;
    const invoiced = await this.facade.invoice(companyId, id);
    if (invoiced?.invoiceId) {
      this.feedback.success('quotes.invoiced');
      await this.router.navigate(['/invoices', invoiced.invoiceId]);
    }
  }

  /** « Facture d'acompte »: the share asked in a dialog, then the new draft opened, the quote's deposits listing it. */
  protected async deposit(): Promise<void> {
    const companyId = this.company()?.id;
    const quote = this.current();
    const scale = this.scale();
    if (!companyId || !quote || scale === null || this.busy()) return;
    const share = await firstValueFrom(
      this.dialog
        .open<QuoteDepositDialog, QuoteDepositDialogData, DepositShare | null>(QuoteDepositDialog, {
          data: { total: quote.total, scale },
          autoFocus: 'first-tabbable',
        })
        .afterClosed(),
    );
    if (!share) return;
    const drawn = await this.facade.deposit(companyId, quote.id, share);
    const latest = drawn?.deposits.at(-1);
    if (latest) {
      this.feedback.success('quotes.deposit_drafted');
      await this.router.navigate(['/invoices', latest.invoiceId]);
    }
  }

  /** Every deposit but a cancelled draft, which never charged anything. */
  protected readonly deposits = computed<QuoteDeposit[]>(() =>
    (this.current()?.deposits ?? []).filter((deposit) => deposit.status !== 'cancelled'),
  );

  protected async cancel(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (!companyId || id === null || this.busy()) return;
    const kind = kindAmong(this.actions(), 'cancel');
    const cancelled = await this.facade.cancel(companyId, id);
    if (cancelled !== null && kind !== undefined)
      this.feedback.effect('quotes.cancelled', {}, kind);
  }

  protected async upload(file: File): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (!companyId || id === null || this.busy()) return;
    if (await this.facade.attach(companyId, id, file)) {
      this.feedback.success('quotes.attachments.added', { name: file.name });
    }
  }

  protected async detach(attachment: QuoteAttachment): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (!companyId || id === null || this.busy()) return;
    if (await this.facade.detach(companyId, id, attachment.id)) {
      this.feedback.success('quotes.attachments.removed', { name: attachment.name });
    }
  }

  protected attachmentUrl(attachment: QuoteAttachment): string {
    const companyId = this.company()?.id ?? '';
    return this.facade.attachmentUrl(companyId, this.id() ?? '', attachment.id);
  }

  protected kilobytes(size: number): number {
    return Math.max(1, Math.round(size / 1024));
  }

  /** The header and the lines as the API takes them, or null after showing what is wrong. */
  private collect(): QuoteInput | null {
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
    return quoteInput(form.getRawValue(), lines, customerId);
  }
}
