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
import { buildFormGroup } from '../shared/form/form-builder';
import { AmountPipe, DayPipe } from '../shared/i18n/format-pipes';
import { StatusBadge } from '../shared/ui/status-badge';
import {
  deliveryNoteForm,
  deliveryNoteInput,
  deliveryNoteValues,
  linesArray,
} from './delivery-note-forms';
import { DeliveryNoteLines } from './delivery-note-lines';
import { DeliveryNotesFacade } from './delivery-notes-facade';
import {
  DELIVERY_NOTE_STATUS_TONES,
  type DeliveryNoteInput,
  type TaxFamily,
} from './delivery-notes-types';

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
    StatusBadge,
  ],
  templateUrl: './delivery-note-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DeliveryNotePage {
  private readonly facade = inject(DeliveryNotesFacade);
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);

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
  protected readonly saved = signal(false);
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
  /** A note needs a customer: a company without one is told so rather than shown a form it cannot fill. */
  protected readonly noCustomers = computed(
    () => this.current() === null && this.options()?.customers.length === 0,
  );
  protected readonly descriptor = computed(() => {
    const options = this.options();
    const current = this.current();
    if (options === null || current === undefined || this.noCustomers()) return null;
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
  protected readonly lines = computed(() => {
    if (this.formKey() === null) return null;
    return untracked(() => {
      const options = this.options();
      const current = this.current();
      if (options === null || current === undefined) return null;
      return linesArray(current?.lines ?? [], options);
    });
  });

  private readonly customerId = signal('');
  protected readonly excludedFamilies = computed<readonly TaxFamily[]>(
    () =>
      this.options()?.customers.find((customer) => customer.id === this.customerId())
        ?.excludedFamilies ?? [],
  );

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
  protected readonly canCancel = computed(() => {
    const status = this.current()?.status;
    return (status === 'draft' || status === 'validated') && this.mayValidate();
  });

  constructor() {
    effect(() => {
      const companyId = this.company()?.id;
      const id = this.id();
      untracked(() => {
        this.saved.set(false);
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
    // The taxes a line offers follow the customer chosen in the header.
    effect((onCleanup) => {
      const control = this.form()?.controls['customerId'];
      if (control === undefined) return;
      untracked(() => this.customerId.set(String(control.value ?? '')));
      const subscription = control.valueChanges.subscribe((value) =>
        this.customerId.set(String(value ?? '')),
      );
      onCleanup(() => subscription.unsubscribe());
    });
  }

  protected onDeliveredOn(event: Event): void {
    this.deliveredOn.set((event.target as HTMLInputElement).value);
  }

  protected async save(): Promise<void> {
    const companyId = this.company()?.id;
    const input = this.collect();
    if (!companyId || input === null) return;
    const id = this.id();
    this.saved.set(false);
    if (id === null) {
      const created = await this.facade.create(companyId, input);
      if (created !== null) {
        await this.router.navigate(['/delivery-notes', created.id], { replaceUrl: true });
      }
    } else if ((await this.facade.revise(companyId, id, input)) !== null) {
      this.saved.set(true);
    }
  }

  protected async validate(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    const input = this.collect();
    if (!companyId || id === null || input === null) return;
    this.saved.set(false);
    await this.facade.reviseAndValidate(companyId, id, input);
  }

  protected async deliver(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (!companyId || id === null || this.busy()) return;
    const day = this.deliveredOn().trim();
    await this.facade.deliver(companyId, id, day === '' ? null : day);
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
    if (form.invalid || lines.invalid) {
      form.markAllAsTouched();
      lines.markAllAsTouched();
      return null;
    }
    return deliveryNoteInput(form.getRawValue(), lines);
  }
}
