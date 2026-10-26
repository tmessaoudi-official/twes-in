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
import { FormControl } from '@angular/forms';
import { MatDialog } from '@angular/material/dialog';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { firstValueFrom } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import type { PlannedAction } from '../shared/actions/planned-actions';
import { kindAmong, type ScreenAction } from '../shared/actions/screen-action';
import { ScreenActions } from '../shared/actions/screen-actions';
import { productsIntoLines, takeInAdded, type TakenIn } from '../shared/documents/add-to';
import { Feedback } from '../shared/feedback/feedback';
import { DescriptorForm } from '../shared/form/descriptor-form';
import type { FieldRefusal } from '../shared/form/field-refusals';
import { RefusalFigures } from '../shared/form/refusal-figures';
import { RefusalLine } from '../shared/documents/refusal-line';
import { unsavedChanges } from '../shared/form/dirty-count';
import { buildFormGroup } from '../shared/form/form-builder';
import { liveRecord } from '../shared/form/live-record';
import { PartConflict } from '../shared/form/part-conflict';
import type { PickOption } from '../shared/form/pick-field';
import { RecordChanged } from '../shared/form/record-changed';
import { RecordView } from '../shared/form/record-view';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { AmountPipe, DayPipe } from '../shared/i18n/format-pipes';
import { RecordDeletes } from '../shared/records/record-deletes';
import { StatusBadge } from '../shared/ui/status-badge';
import { DocumentActions } from '../shared/ui/document-actions';
import {
  type LineGroup,
  lineGroup,
  linesArray,
  plainDecimal,
  purchaseOrderForm,
  purchaseOrderInput,
  purchaseOrderValues,
  refusedPlace,
} from './purchase-order-forms';
import { PurchaseOrderLines } from './purchase-order-lines';
import { PurchasesFacade } from './purchases-facade';
import {
  type ProductOption,
  PURCHASE_ORDER_STATUS_STAGES,
  PURCHASE_ORDER_STATUS_TONES,
  type PurchaseOrderInput,
  type Receipt,
  receivable,
  type VendorOption,
} from './purchases-types';
import { ReceiveDialog, type ReceiveDialogData } from './receive-dialog';
import { ReceiverDialog, type ReceiverDialogData } from '../shared/receivers/receiver-dialog';
import { sayReceiverUndoRefused } from '../shared/receivers/receiver-undo';
import type { Receiver } from '../shared/receivers/receiver';

/** What an order will offer once its planned modules ship, shown « Bientôt » beside what it offers today. */
export const PURCHASE_ORDER_PLANNED: readonly PlannedAction[] = [
  { module: 'mailing', label: 'planned_actions.send_email', icon: 'forward_to_inbox' },
];

/**
 * One order to a vendor: a new draft to fill in, a draft to revise and send, or a sent order whose goods are received,
 * all at once or in parts, then closed when the rest will not come. Only a draft changes; the API refuses anything else
 * whatever this screen shows.
 */
@Component({
  selector: 'app-purchase-order-page',
  imports: [
    MatButtonModule,
    MatCardModule,
    RouterLink,
    TranslatePipe,
    AmountPipe,
    DayPipe,
    DescriptorForm,
    DocumentActions,
    PartConflict,
    PurchaseOrderLines,
    RecordChanged,
    RecordView,
    StatusBadge,
  ],
  templateUrl: './purchase-order-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PurchaseOrderPage {
  private readonly facade = inject(PurchasesFacade);
  private readonly linesEditor = viewChild(PurchaseOrderLines);
  private readonly headerEditor = viewChild(DescriptorForm);
  private readonly unsaved = inject(UnsavedChanges);
  private readonly dialog = inject(MatDialog);
  private readonly feedback = inject(Feedback);
  private readonly router = inject(Router);
  private readonly deletes = inject(RecordDeletes);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly auth = inject(AuthFacade);

  /** Bound from the route parameter by withComponentInputBinding(); absent on `purchases/new`. */
  readonly purchaseOrderId = input<string | undefined>(undefined);
  /** `?vendorId=` on a new order: the vendor it was started from, named in the header until changed. */
  readonly vendorId = input<string | undefined>(undefined);
  /** `?add=` from « Ajouter à… »: the products to put on the order's lines, once, unsaved. */
  readonly add = input<string | readonly string[] | undefined>(undefined);

  protected readonly id = computed(() => this.purchaseOrderId() ?? null);
  protected readonly tones = PURCHASE_ORDER_STATUS_TONES;
  protected readonly stages = PURCHASE_ORDER_STATUS_STAGES;
  protected readonly plain = plainDecimal;
  protected readonly busy = this.facade.busy;
  private readonly translate = inject(TranslateService);
  protected readonly error = this.facade.error;
  private readonly refusalFigures = inject(RefusalFigures);
  /** The line above the form: what no field took, or the error's own words. */
  protected readonly refusalLine = new RefusalLine(
    this.facade.refusals,
    'purchases.errors.',
    this.translate,
    this.refusalFigures,
  );
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('purchase.write'));
  protected readonly mayReceive = computed(() => this.auth.hasPermission('purchase.receive'));
  /** A price on an order is a cost: shown, typed and sent only by whoever may read costs. */
  protected readonly seesCost = computed(() => this.auth.hasPermission('product.cost.read'));
  protected readonly descriptor = purchaseOrderForm();

  /** Null while a new order is filled in; undefined until the order asked for has been read. */
  protected readonly current = computed(() => {
    const id = this.id();
    if (id === null) return null;
    const order = this.facade.order();
    return order?.id === id ? order : undefined;
  });
  protected readonly editable = computed(() => {
    const current = this.current();
    return this.mayWrite() && (current === null || current?.status === 'draft');
  });
  /**
   * What the form is of: the order and its state. Reading the order again yields new objects with the same content and
   * must not rebuild the form over what is being typed; another order or another state must.
   */
  private readonly formKey = computed(() => {
    const current = this.current();
    return current === undefined ? null : `${current?.id ?? 'new'}|${current?.status ?? 'draft'}`;
  });
  protected readonly form = computed(() => {
    if (this.formKey() === null) return null;
    return untracked(() => {
      const current = this.current();
      if (current === undefined) return null;
      return buildFormGroup(this.descriptor, purchaseOrderValues(current, this.vendorId() ?? null));
    });
  });
  /** Bumped to show the saved lines again in place of the ones shown. */
  private readonly linesVersion = signal(0);
  protected readonly lines = computed(() => {
    if (this.formKey() === null) return null;
    this.linesVersion();
    return untracked(() => {
      const current = this.current();
      return current === undefined ? null : linesArray(current?.lines ?? null);
    });
  });
  protected readonly isLinesConflict = (conflict: { field: string }): boolean =>
    conflict.field === 'lines';

  /** The saved version the order stands on, and what another person's save changed in it. */
  protected readonly sync = liveRecord({
    kind: 'purchase_order',
    id: this.id,
    form: this.form,
    reload: async () => {
      const companyId = this.company()?.id;
      const id = this.id();
      if (companyId && id !== null) await this.facade.loadOrder(companyId, id);
    },
    saved: () => {
      const current = this.current();
      return current ? purchaseOrderValues(current) : null;
    },
    version: () => this.current()?.version ?? null,
    // The lines are one field: a line is never merged with another person's.
    parts: [
      {
        field: 'lines',
        saved: () => {
          const current = this.current();
          return current ? JSON.stringify(linesArray(current.lines).getRawValue()) : null;
        },
        shown: () => JSON.stringify(this.lines()?.getRawValue() ?? []),
        take: () => this.linesVersion.update((version) => version + 1),
      },
    ],
  });

  /** What is typed and not saved, header and lines each, so leaving the page asks first. */
  private readonly savedHeader = computed(() => {
    const current = this.current();
    return current === undefined ? null : purchaseOrderValues(current, this.vendorId() ?? null);
  });
  protected readonly headerChanges = unsavedChanges(this.form, this.savedHeader);
  private readonly savedLines = computed(() => {
    const current = this.current();
    return current === undefined ? null : linesArray(current?.lines ?? null).getRawValue();
  });
  protected readonly lineChanges = unsavedChanges(this.lines, this.savedLines);

  /** Who the header names, as the picker answered it; the form carries the id. */
  private readonly vendor = signal<VendorOption | null>(null);
  private readonly knownVendors = new Map<string, VendorOption>();
  protected readonly vendorShown = computed((): PickOption | null => {
    const vendor = this.vendor();
    return vendor === null ? null : { id: vendor.id, code: vendor.number, name: vendor.name };
  });
  protected readonly searchVendors = async (words: string): Promise<readonly PickOption[]> => {
    const companyId = this.company()?.id;
    if (!companyId) return [];
    const found = await this.facade.pickVendors(companyId, { words });
    for (const vendor of found) this.knownVendors.set(vendor.id, vendor);
    return found.map((vendor) => ({ id: vendor.id, code: vendor.number, name: vendor.name }));
  };
  /** The products the saved order names, read by id once it is open. */
  protected readonly named = signal<ReadonlyMap<string, ProductOption>>(new Map());
  /** The vendor's own code for each product the saved order names, read again on every save. */
  protected readonly vendorCodes = computed(
    () =>
      new Map(
        (this.current()?.lines ?? []).flatMap((line) =>
          line.vendorCode === null ? [] : [[line.productId, line.vendorCode] as const],
        ),
      ),
  );

  /** The order read rather than filled in, once it no longer changes. */
  protected readonly asView = computed(() => !this.editable() && this.current() != null);
  /** Whom a sent order went to, as it recorded them: number and name. */
  protected readonly viewPicked = computed((): Record<string, string> => {
    const current = this.current();
    if (!current) return {};
    const number = current.recordedVendorNumber ?? this.vendor()?.number ?? null;
    return { vendorId: number === null ? current.vendorName : `${number} · ${current.vendorName}` };
  });
  /** Where the last save was refused on a line, by line and field. */
  protected readonly refusedLine = computed(() => {
    const field = this.facade.refused();
    if (field === null) return null;
    const place = refusedPlace(field);
    return place.line === null ? null : place;
  });
  /** Each receipt's lines with what they brought of which line, for the history under the lines. */
  protected readonly receipts = computed(() => {
    const current = this.current();
    if (!current) return [];
    const byId = new Map(current.lines.map((line) => [line.id, line]));
    return [...current.receipts].reverse().map((receipt) => ({
      ...receipt,
      lines: receipt.lines.map((line) => ({ ...line, of: byId.get(line.orderLineId) ?? null })),
    }));
  });

  protected readonly planned = computed(() => (this.current() ? PURCHASE_ORDER_PLANNED : []));
  protected readonly canSend = computed(
    () => this.current()?.status === 'draft' && this.mayWrite(),
  );
  protected readonly canReceive = computed(() => {
    const status = this.current()?.status;
    return status !== undefined && receivable(status) && this.mayReceive();
  });
  protected readonly canClose = computed(
    () => this.current()?.status === 'partly_received' && this.mayWrite(),
  );
  protected readonly canCancel = computed(() => {
    const status = this.current()?.status;
    return (status === 'draft' || status === 'sent') && this.mayWrite();
  });

  /**
   * What the order offers, declared once for the bar beside its title. The state's next step is the primary one:
   * sending a draft, receiving a sent order or the rest of one partly received.
   */
  protected readonly actions = computed<ScreenAction[]>(() => {
    const busy = this.busy();
    return [
      {
        id: 'save',
        shortcut: 's',
        label: 'purchases.actions.save',
        icon: 'save',
        // Kept in a phone's bar, or a new order there offers no way to save it.
        keep: true,
        disabled: busy,
        run: () => void this.save(),
        shown: this.editable() && this.form() !== null,
      },
      {
        id: 'send',
        next: true,
        label: 'purchases.actions.send',
        icon: 'send',
        primary: true,
        disabled: busy,
        run: () => void this.send(),
        shown: this.canSend(),
        confirm: {
          kind: 'corrigeable',
          title: 'purchases.actions.send_title',
          message: 'purchases.actions.send_message',
          confirmLabel: 'purchases.actions.send',
          keepLabel: 'purchases.actions.keep',
        },
      },
      {
        id: 'receive',
        next: true,
        label: 'purchases.actions.receive',
        icon: 'move_to_inbox',
        primary: true,
        disabled: busy,
        run: () => void this.openReceive(),
        shown: this.canReceive(),
      },
      {
        id: 'pdf',
        label:
          this.current()?.status === 'draft'
            ? 'purchases.actions.pdf_draft'
            : 'purchases.actions.pdf',
        icon: 'picture_as_pdf',
        href: this.pdfUrl() ?? undefined,
        shown: this.pdfUrl() !== null,
      },
      {
        id: 'close',
        label: 'purchases.actions.close',
        icon: 'check_circle',
        disabled: busy,
        run: () => void this.close(),
        shown: this.canClose(),
        confirm: {
          kind: 'definitif',
          title: 'purchases.actions.close_title',
          message: 'purchases.actions.close_message',
          confirmLabel: 'purchases.actions.close',
          keepLabel: 'purchases.actions.keep',
        },
      },
      {
        id: 'cancel',
        label: 'purchases.actions.cancel',
        icon: 'block',
        destructive: true,
        disabled: busy,
        run: () => void this.cancel(),
        shown: this.canCancel(),
        confirm: {
          kind: 'definitif',
          title: 'purchases.actions.cancel_title',
          message: 'purchases.actions.cancel_message',
          confirmLabel: 'purchases.actions.confirm_cancel',
          keepLabel: 'purchases.actions.keep',
        },
      },
      {
        id: 'delete',
        label: 'records.delete',
        icon: 'delete',
        // Asks nothing: the toast offers « Annuler », and « Récemment supprimés » keeps the draft a day. A draft a receipt
        // names is refused, the toast saying by what.
        destructive: true,
        undoable: true,
        disabled: busy,
        run: () => void this.remove(),
        shown: this.current()?.status === 'draft' && this.mayWrite(),
      },
    ];
  });

  /** Deletes the draft, then leaves its page for the list once it is gone; what was typed in it goes with it. */
  private async remove(): Promise<void> {
    const companyId = this.company()?.id;
    const current = this.current();
    if (!companyId || !current) return;
    const gone = await this.deletes.remove(companyId, 'purchase_order', [
      { id: current.id, label: current.number ?? current.vendorName },
    ]);
    if (!gone) return;
    this.unsaved.savedAndLeaving();
    await this.router.navigate(['/purchases']);
  }

  /** The printed order carries its prices, which are costs: only a cost reader is offered it. */
  protected readonly pdfUrl = computed(() => {
    const companyId = this.company()?.id;
    const current = this.current();
    return companyId && current && this.seesCost()
      ? this.facade.pdfUrl(companyId, current.id)
      : null;
  });

  constructor() {
    // The same list the bar draws also answers the keyboard, the palette and the "?" sheet.
    inject(ScreenActions).declare(this.actions);
    takeInAdded({
      add: this.add,
      ready: () => {
        const current = this.current();
        if (current === undefined || this.lines() === null) return null;
        return { id: current?.id ?? null, editable: this.editable() };
      },
      take: (asked) => this.takeIn(asked),
    });
    effect(() => {
      const companyId = this.company()?.id;
      const id = this.id();
      untracked(() => {
        if (companyId) void this.facade.loadOrder(companyId, id);
      });
    });
    // The picker shows what the form names: picked here, or read by id when the form opens on one.
    effect((onCleanup) => {
      const form = this.form();
      const companyId = this.company()?.id;
      if (form === null || !companyId) return;
      const show = (vendorId: unknown): void => {
        if (typeof vendorId !== 'string' || vendorId === '') {
          this.vendor.set(null);
          return;
        }
        if (this.vendor()?.id === vendorId) return;
        const known = this.knownVendors.get(vendorId);
        if (known) {
          this.vendor.set(known);
          return;
        }
        void this.facade.pickVendors(companyId, { ids: [vendorId] }).then(([found]) => {
          if (found) this.knownVendors.set(found.id, found);
          if (form.controls['vendorId']?.value === vendorId) this.vendor.set(found ?? null);
        });
      };
      untracked(() => show(form.controls['vendorId']?.value));
      const subscription = form.controls['vendorId']?.valueChanges
        .pipe(takeUntilDestroyed(this.destroyRef))
        .subscribe(show);
      onCleanup(() => subscription?.unsubscribe());
    });
    // The products the order names, read once per order: a line shows its product and its unit before any search.
    effect(() => {
      const companyId = this.company()?.id;
      const current = this.current();
      if (!companyId || !current) return;
      const ids = [...new Set(current.lines.map((line) => line.productId))].sort();
      untracked(async () => {
        if (ids.every((id) => this.named().has(id))) return;
        const found = await this.facade.pickProducts(companyId, { ids });
        this.named.update((named) => new Map([...named, ...found.map((p) => [p.id, p] as const)]));
      });
    });
    effect(() => {
      const lines = this.lines();
      const editable = this.editable();
      if (lines === null) return;
      untracked(() => (editable ? lines.enable() : lines.disable()));
    });
  }

  protected async save(): Promise<void> {
    const companyId = this.company()?.id;
    const input = this.collect();
    if (!companyId || input === null) return;
    const id = this.id();
    if (id === null) {
      const created = await this.facade.create(companyId, input);
      if (created !== null) {
        this.feedback.success('purchases.saved');
        // It exists now: going to it is not leaving unsaved work.
        this.unsaved.savedAndLeaving();
        await this.router.navigate(['/purchases', created.id], { replaceUrl: true });
      } else {
        await this.refused();
      }
    } else if ((await this.facade.revise(companyId, id, input, this.sync.version())) !== null) {
      this.revisedHere();
      this.feedback.success('purchases.saved');
    } else {
      await this.refused();
    }
  }

  /**
   * The form and the lines now stand on what this tab saved: the lines are drawn again from what the API kept, so a
   * price typed « 2,50 » and kept as 2.5 reads as saved rather than as a change.
   */
  private readonly revisedHere = (): void => {
    const form = this.form();
    if (form !== null) this.sync.savedHere(form);
    const lines = this.lines();
    const saved = this.current()?.lines ?? null;
    if (lines !== null && saved !== null && saved.length === lines.length) {
      // Written into the lines shown rather than drawn again, so the field typed into keeps its place and its focus.
      lines.setValue(linesArray(saved).getRawValue());
      lines.markAsPristine();
      lines.markAsUntouched();
    } else {
      this.linesVersion.update((version) => version + 1);
    }
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
   * Each field the API refused, said under it: a line's on its line, the vendor and the rest of the header in its
   * form. The line above the form stays for what is refused as a whole.
   */
  private showRefused(refusals: readonly FieldRefusal[]): void {
    if (refusals.length === 0) return;
    const header = this.linesEditor()?.showRefused(refusals) ?? refusals;
    const unplaced = header.length > 0 ? (this.headerEditor()?.showRefused(header) ?? header) : [];
    this.refusalLine.placed(refusals, unplaced);
  }

  protected async send(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    const input = this.collect();
    if (!companyId || id === null || input === null) return;
    const kind = kindAmong(this.actions(), 'send');
    const sent = await this.facade.reviseAndSend(
      companyId,
      id,
      input,
      this.sync.version(),
      this.revisedHere,
    );
    if (sent === null) await this.refused();
    if (sent === null || kind === undefined) return;
    const next = this.mayReceive()
      ? { key: 'purchases.suggest.receive', run: () => void this.openReceive() }
      : undefined;
    this.feedback.effect('purchases.sent', { number: sent.number ?? '' }, kind, next);
  }

  /** The goods that came, asked in a dialog that records them itself and stays open on a refusal. */
  protected async openReceive(): Promise<void> {
    const companyId = this.company()?.id;
    const current = this.current();
    if (!companyId || !current || this.busy()) return;
    const receiving = await this.facade.receiving(companyId, current.id);
    if (receiving === null) return;
    const rule = current.receiverRule ?? 'optional';
    const data: ReceiveDialogData = {
      ...(rule === 'off'
        ? {}
        : {
            receiver: {
              required: rule === 'required',
              members: this.facade.receivers(companyId),
            },
          }),
      lines: current.lines,
      receiving,
      seesCost: this.seesCost(),
      timeZone: this.company()?.timezone ?? null,
      issueDate: current.issueDate,
      receive: async (input) => {
        const order = await this.facade.receive(companyId, current.id, input);
        if (order !== null) return null;
        const field = this.facade.refused();
        const refusals = this.facade.refusals();
        return {
          code: this.facade.error() ?? 'network',
          field,
          refusal: refusals.find((refusal) => refusal.field === field) ?? refusals[0] ?? null,
        };
      },
    };
    const received = await firstValueFrom(
      this.dialog
        .open<ReceiveDialog, ReceiveDialogData, boolean>(ReceiveDialog, {
          data,
          autoFocus: 'first-tabbable',
          width: '48rem',
          maxWidth: '100vw',
        })
        .afterClosed(),
    );
    if (received === true) {
      const status = this.facade.order()?.status;
      this.feedback.success(
        status === 'received' ? 'purchases.received_all' : 'purchases.received_part',
      );
    } else {
      // The dialog said why; the page behind it keeps nothing of a receipt left unrecorded.
      this.facade.clearError();
    }
  }

  /**
   * Whether a receipt's « Reçu par » can be put right here: by whoever receives goods, while the company's receipts
   * name someone, or to take away a name recorded before they were switched off.
   */
  protected receiverCorrectable(receipt: Receipt): boolean {
    return (
      this.mayReceive() &&
      ((this.current()?.receiverRule ?? 'optional') !== 'off' || receipt.receivedBy !== null)
    );
  }

  /** « Reçu par » put right on a receipt, in the dialog every document shares; said once saved. */
  protected async correctReceiver(receipt: Receipt): Promise<void> {
    const companyId = this.company()?.id;
    const current = this.current();
    if (!companyId || !current || this.busy()) return;
    const saved = await firstValueFrom(
      this.dialog
        .open<ReceiverDialog, ReceiverDialogData, boolean>(ReceiverDialog, {
          data: {
            title: 'purchases.receipts.correct_receiver',
            current: receipt.receivedBy,
            required: current.receiverRule === 'required',
            contacts: this.facade.receivers(companyId),
            offersAdding: false,
            pickedHint: 'receivers.a_member',
            save: (receivedBy) =>
              this.facade.correctReceiver(companyId, current.id, receipt.id, receivedBy),
          },
          autoFocus: 'first-tabbable',
        })
        .afterClosed(),
    );
    if (saved === true) this.saidCorrected(companyId, current.id, receipt.id, receipt.receivedBy);
  }

  /**
   * Said once put right; « Annuler » names again whoever was named before: the same member, the same name typed, or
   * nobody.
   */
  private saidCorrected(
    companyId: string,
    id: string,
    receiptId: string,
    before: Receiver | null,
  ): void {
    const shown =
      this.current()?.receipts.find((receipt) => receipt.id === receiptId)?.receivedBy ?? null;
    this.feedback.effect('purchases.receipts.receiver_corrected', {}, 'corrigeable', {
      key: 'receivers.undo',
      run: () =>
        void this.facade
          .correctReceiver(
            companyId,
            id,
            receiptId,
            before === null
              ? null
              : before.contactId !== null
                ? { contactId: before.contactId, name: null, addToContacts: false }
                : { contactId: null, name: before.name, addToContacts: false },
          )
          .then((answer) => {
            if (answer === null) return;
            if ('kept' in answer) this.saidCorrected(companyId, id, receiptId, shown);
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

  protected async close(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (!companyId || id === null || this.busy()) return;
    const kind = kindAmong(this.actions(), 'close');
    const closed = await this.facade.close(companyId, id);
    if (closed !== null && kind !== undefined) this.feedback.effect('purchases.closed', {}, kind);
  }

  protected async cancel(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (!companyId || id === null || this.busy()) return;
    const kind = kindAmong(this.actions(), 'cancel');
    const cancelled = await this.facade.cancel(companyId, id);
    if (cancelled !== null && kind !== undefined) {
      this.feedback.effect('purchases.cancelled', {}, kind);
    }
  }

  /**
   * The products « Ajouter à… » sent here, put on the lines as scans would be, each filled in as a product chosen on a
   * line is. Only active goods are ordered from a vendor: a service or a retired product is left out, and said.
   */
  private async takeIn(asked: readonly string[]): Promise<TakenIn | null> {
    const lines = this.lines();
    const companyId = this.company()?.id;
    if (lines === null || !companyId) return null;
    const found = await this.facade.pickProducts(companyId, { ids: [...asked] });
    const orderable = found.filter((product) => product.goods && product.active);
    this.named.update(
      (named) => new Map([...named, ...orderable.map((product) => [product.id, product] as const)]),
    );
    // An order line counts in its product's unit, so a line naming a product sent here counts in the unit it was read with.
    const units = new Map(orderable.map((product) => [product.id, product.unitId]));
    const seesCost = this.seesCost();
    const taken = productsIntoLines<LineGroup, ProductOption>(
      lines,
      (line) => ({
        ...line.controls,
        unitId: new FormControl(units.get(line.controls.productId.value) ?? '', {
          nonNullable: true,
        }),
      }),
      asked,
      orderable,
      (product) => {
        const line = lineGroup(null);
        line.controls.productId.setValue(product.id);
        line.controls.description.setValue(product.name);
        if (seesCost && product.costPrice !== null) {
          line.controls.unitPriceNet.setValue(plainDecimal(product.costPrice));
        }
        return line;
      },
      () => undefined,
    );
    const refused = found.length - orderable.length;
    return {
      added: taken.added,
      leftOut: taken.leftOut - refused,
      refused: { count: refused, key: 'documents.add_to.left_out_goods' },
    };
  }

  /** The header and the lines as the API takes them, or null after showing what is wrong with them. */
  private collect(): PurchaseOrderInput | null {
    const form = this.form();
    const lines = this.lines();
    if (form === null || lines === null || this.busy()) return null;
    if (form.invalid || lines.invalid) {
      form.markAllAsTouched();
      lines.markAllAsTouched();
      return null;
    }
    return purchaseOrderInput(form.getRawValue(), lines, this.seesCost());
  }
}
