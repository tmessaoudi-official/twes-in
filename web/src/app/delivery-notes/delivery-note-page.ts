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
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { liveRecord } from '../shared/form/live-record';
import { PartConflict } from '../shared/form/part-conflict';
import { RecordChanged } from '../shared/form/record-changed';
import { buildFormGroup } from '../shared/form/form-builder';
import { AmountPipe, DayPipe } from '../shared/i18n/format-pipes';
import { StatusBadge } from '../shared/ui/status-badge';
import {
  deliveryNoteForm,
  deliveryNoteInput,
  deliveryNoteValues,
  linesArray,
  type LineGroup,
  lineGroup,
  applyProduct,
  pickedCustomer,
} from './delivery-note-forms';
import { ProductScans } from '../products/product-scans';
import { type Scan, ScanBus, type ScanOutcome } from '../shared/scan/scan-bus';
import { placedOutcome, scanIntoLines } from '../shared/scan/scan-lines';
import { PickField, type PickOption } from '../shared/form/pick-field';
import { DeliveryNoteLines } from './delivery-note-lines';
import { DeliveryNotesFacade } from './delivery-notes-facade';
import {
  type CustomerOption,
  DELIVERY_NOTE_STATUS_TONES,
  type DeliveryNoteInput,
  type TaxFamily,
} from './delivery-notes-types';
import { Feedback } from '../shared/feedback/feedback';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { MatDialog } from '@angular/material/dialog';
import { firstValueFrom } from 'rxjs';
import { DocumentActions } from '../shared/ui/document-actions';
import type { ScreenAction } from '../shared/actions/screen-action';
import { ScreenActions } from '../shared/actions/screen-actions';
import { DeliverDialog } from './deliver-dialog';
import { RecordView } from '../shared/form/record-view';

/**
 * One delivery note: a new draft to fill in, a draft to revise and validate, or a numbered note to deliver, cancel
 * and print. Only a draft changes; the API refuses anything else whatever this screen shows.
 */
@Component({
  selector: 'app-delivery-note-page',
  imports: [
    MatButtonModule,
    MatCardModule,
    MatFormFieldModule,
    MatInputModule,
    RouterLink,
    TranslatePipe,
    AmountPipe,
    DayPipe,
    DescriptorForm,
    DeliveryNoteLines,
    DocumentActions,
    RecordView,
    PartConflict,
    PickField,
    RecordChanged,
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
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);
  private readonly productScans = inject(ProductScans);

  /** Bound from the route parameter by withComponentInputBinding(); absent on `delivery-notes/new`. */
  readonly deliveryNoteId = input<string | undefined>(undefined);

  protected readonly id = computed(() => this.deliveryNoteId() ?? null);
  protected readonly scale = computed(() => this.options()?.currencyScale ?? null);
  protected readonly tones = DELIVERY_NOTE_STATUS_TONES;
  protected readonly options = this.facade.options;
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('delivery_note.write'));
  protected readonly mayValidate = computed(() =>
    this.auth.hasPermission('delivery_note.validate'),
  );
  protected readonly confirmingCancel = signal(false);
  protected readonly deliveredOn = signal('');

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
    // The lines are one field: a line is never merged with another person's.
    parts: [
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
  protected readonly customerShown = computed(() => pickedCustomer(this.customer()));
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
        disabled: busy,
        run: () => void this.save(),
        shown: this.editable() && this.form() !== null,
      },
      {
        id: 'validate',
        shortcut: 'v',
        label: 'delivery_notes.actions.validate',
        icon: 'check',
        primary: true,
        disabled: busy,
        run: () => void this.validate(),
        shown: this.canValidate(),
      },
      {
        id: 'deliver',
        shortcut: 'l',
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
        icon: 'receipt_long',
        primary: true,
        disabled: busy,
        run: () => void this.invoice(),
        shown: this.canInvoice(),
      },
      {
        id: 'pdf',
        label: 'delivery_notes.actions.pdf',
        icon: 'picture_as_pdf',
        href: this.pdfUrl() ?? undefined,
        shown: this.pdfUrl() !== null,
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
          title: 'delivery_notes.actions.cancel_title',
          message: 'delivery_notes.actions.cancel_message',
          confirmLabel: 'delivery_notes.actions.confirm_cancel',
          keepLabel: 'delivery_notes.actions.keep',
        },
      },
    ];
  });

  protected readonly pdfUrl = computed(() => {
    const companyId = this.company()?.id;
    const current = this.current();
    return companyId && current ? this.facade.pdfUrl(companyId, current.id) : null;
  });
  protected readonly canValidate = computed(
    () => this.current()?.status === 'draft' && this.mayWrite() && this.mayValidate(),
  );
  protected readonly canDeliver = computed(
    () => this.current()?.status === 'validated' && this.mayWrite(),
  );
  /** A validated or delivered note becomes an invoice, while the invoices module is on for a writer of invoices. */
  protected readonly canInvoice = computed(() => {
    const status = this.current()?.status;
    return (
      (status === 'validated' || status === 'delivered') &&
      this.auth.hasModule('invoices') &&
      this.auth.hasPermission('invoice.write')
    );
  });
  protected readonly canCancel = computed(() => {
    const status = this.current()?.status;
    return (status === 'draft' || status === 'validated') && this.mayValidate();
  });

  /**
   * A scan on a draft puts its product on the lines as a till does (docs/SPEC.md § 7, 2026-09-23 09:30), the same
   * rule the invoice follows. Anywhere else the card of what it names takes the scan.
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

    const excluded = this.excludedFamilies();
    const placed = scanIntoLines<LineGroup>(
      lines,
      (line) => line.controls,
      {
        productId: product.id,
        unitId: product.unitId,
        count: Math.max(named.quantity, 1) * scan.times,
      },
      () => {
        const line = lineGroup(null, options);
        applyProduct(line, product, options, excluded);
        return line;
      },
    );
    return placedOutcome(placed, product.name);
  }

  constructor() {
    // The same list the bar draws also answers the keyboard, the palette and the "?" sheet (row 45): one
    // declaration, so an action cannot be offered in one of them and missing from another.
    inject(ScreenActions).declare(this.actions);
    inject(ScanBus).handle((scan) => this.scanned(scan));
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
    this.customer.set(customer);
    this.customerMissing.set(customer === null);
  }

  protected onDeliveredOn(event: Event): void {
    this.deliveredOn.set((event.target as HTMLInputElement).value);
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
        await this.router.navigate(['/delivery-notes', created.id], { replaceUrl: true });
      }
    } else if ((await this.facade.revise(companyId, id, input)) !== null) {
      const form = this.form();
      if (form !== null) this.sync.savedHere(form);
      this.feedback.success('delivery_notes.saved');
    }
  }

  protected async validate(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    const input = this.collect();
    if (!companyId || id === null || input === null) return;
    await this.facade.reviseAndValidate(companyId, id, input);
  }

  /** Asked in a dialog, so the bar carries actions and not a date field (design review finding 3). */
  protected async openDeliver(): Promise<void> {
    if (this.busy()) return;
    const day = await firstValueFrom(
      this.dialog
        .open(DeliverDialog, { data: this.deliveredOn(), autoFocus: 'first-tabbable' })
        .afterClosed(),
    );
    if (day !== null && day !== undefined) await this.deliver(day);
  }

  protected async deliver(on: string): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (!companyId || id === null || this.busy()) return;
    const day = on.trim();
    await this.facade.deliver(companyId, id, day === '' ? null : day);
  }

  protected async invoice(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (!companyId || id === null || this.busy()) return;
    const invoiceId = await this.facade.invoice(companyId, id);
    if (invoiceId !== null) {
      await this.router.navigate(['/invoices', invoiceId]);
    }
  }

  protected async cancel(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    this.confirmingCancel.set(false);
    if (!companyId || id === null || this.busy()) return;
    await this.facade.cancel(companyId, id);
  }

  /** The header and the lines as the API takes them, or null after showing what is wrong with them. */
  private collect(): DeliveryNoteInput | null {
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
    return deliveryNoteInput(form.getRawValue(), lines, customerId);
  }
}
