// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  ElementRef,
  inject,
  input,
  output,
  signal,
  untracked,
  viewChild,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { Label } from '../shared/a11y/label';
import { AmountPipe, DayPipe } from '../shared/i18n/format-pipes';
import type { StatusTone } from '../shared/theme/accent-theme';
import { withdrawn } from '../shared/theme/lifecycle-tones';
import { onRecord } from '../shared/actions/record-action';
import { ScreenActions } from '../shared/actions/screen-actions';
import { DocumentActions } from '../shared/ui/document-actions';
import { StatusBadge } from '../shared/ui/status-badge';
import { InvoiceActions } from './invoice-actions';
import { daysLate, paidShare, shownStatus, stillOwed } from './invoice-forms';
import { chaseDue } from './invoice-summary-view';
import { InvoicesFacade } from './invoices-facade';
import { INVOICE_STATUS_STAGES, INVOICE_STATUS_TONES, type InvoiceRow } from './invoices-types';

/** The module a customer's record belongs to: the customer is a link only where it is switched on. */
const CUSTOMERS_MODULE = 'customers';

/**
 * An issued document read over its list (docs/SPEC.md § 7, 2026-09-24 22:51, row 123, and 2026-09-26): what is left
 * to collect and by when, its dates and establishment, its payments, and the way into the record, its payment and its
 * PDF. It reads, it never edits: everything that changes the document happens on the record page, which it opens.
 */
@Component({
  selector: 'app-invoice-sheet',
  imports: [
    MatButtonModule,
    MatIconModule,
    RouterLink,
    TranslatePipe,
    AmountPipe,
    DayPipe,
    Label,
    StatusBadge,
    DocumentActions,
  ],
  templateUrl: './invoice-sheet.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
  // Escape closes it wherever the focus is inside it; opening moves the focus to its heading.
  host: { class: 'block', '(keydown.escape)': 'close()' },
})
export class InvoiceSheet {
  private readonly facade = inject(InvoicesFacade);
  private readonly auth = inject(AuthFacade);
  private readonly invoiceActions = inject(InvoiceActions);

  readonly invoiceId = input.required<string>();
  /** The company's day (YYYY-MM-DD), which says whether the document is late and by how much. */
  readonly today = input.required<string>();
  readonly closed = output<void>();
  /** Said once an action changed the document, so the list reads its rows again: its own change never comes back to it. */
  readonly touched = output<void>();

  private readonly heading = viewChild<ElementRef<HTMLElement>>('heading');
  /** undefined while it is read, null when it cannot be. */
  protected readonly invoice = signal<InvoiceRow | null | undefined>(undefined);
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly options = this.facade.options;
  protected readonly scale = computed(() => this.options()?.currencyScale ?? null);
  protected readonly shown = computed(() => {
    const invoice = this.invoice();
    return invoice ? shownStatus(invoice, this.today()) : null;
  });
  protected readonly tone = computed((): StatusTone => {
    const shown = this.shown();
    return shown === null ? 'neutral' : INVOICE_STATUS_TONES[shown];
  });
  protected readonly struck = computed(() => {
    const shown = this.shown();
    return shown !== null && withdrawn(INVOICE_STATUS_STAGES[shown]);
  });
  protected readonly owed = computed(() => stillOwed(this.invoice()));
  protected readonly customerLinked = computed(() => this.auth.hasModule(CUSTOMERS_MODULE));
  protected readonly share = computed(() => {
    const invoice = this.invoice();
    return invoice ? paidShare(invoice) : 0;
  });
  protected readonly due = computed(() => {
    const dueDate = this.invoice()?.dueDate;
    return dueDate ? chaseDue(daysLate(dueDate, this.today())) : null;
  });
  protected readonly establishment = computed(() => {
    const id = this.invoice()?.establishmentId;
    return this.options()?.establishments.find((one) => one.id === id)?.name ?? null;
  });
  /**
   * What can be done to the document, the same declaration its page and its row read: its next step runs here, over the
   * list, rather than sending the person to the record to do it (docs/SPEC.md § 7, 2026-10-11 08:26).
   */
  private readonly declared = computed(() =>
    this.invoiceActions.declare({ changed: () => this.changed() }),
  );
  protected readonly actions = computed(() =>
    onRecord(this.declared(), this.invoice(), this.facade.busy()),
  );

  constructor() {
    // Its keys and the palette act on the document the sheet shows.
    inject(ScreenActions).declare(this.actions);
    let request = 0;
    effect(() => {
      const companyId = this.company()?.id;
      const id = this.invoiceId();
      untracked(async () => {
        if (!companyId) return;
        // Another row clicked while this one is read: only the last one asked for is shown.
        const asked = ++request;
        this.invoice.set(undefined);
        const read = await this.facade.peek(companyId, id);
        if (asked !== request) return;
        this.invoice.set(read);
        // Opened from the list by a click or a key: the reader goes on from its heading.
        queueMicrotask(() => this.heading()?.nativeElement.focus());
      });
    });
  }

  /** The document read again after an action of its bar, and the list told. */
  private async changed(): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId) return;
    this.invoice.set(await this.facade.peek(companyId, this.invoiceId()));
    this.touched.emit();
  }

  protected close(): void {
    this.closed.emit();
  }
}
