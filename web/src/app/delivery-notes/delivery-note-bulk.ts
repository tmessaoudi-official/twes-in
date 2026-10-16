// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, input, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { firstValueFrom } from 'rxjs';
import { Feedback } from '../shared/feedback/feedback';
import { BulkSkippedDialog, type BulkSkippedDialogData } from '../shared/list/bulk-skipped-dialog';
import { DeliveryNotesRefused } from './delivery-notes-api';
import { DeliveryNotesFacade } from './delivery-notes-facade';
import type { InvoicingCheck } from './delivery-notes-types';
import { askInvoiceTarget, type InvoiceTarget } from './invoice-target';

/**
 * What the delivery notes list offers on the rows a person selected: « Facturer »
 * puts what is left of them on one invoice. The API is asked first what it would take; a note it would leave out (a
 * draft, one already invoiced, another customer's) is named with why before the others go on. Then, as on a note's
 * own page, a draft of the same customer and establishment may take them instead of a new invoice, which opens.
 */
@Component({
  selector: 'app-delivery-note-bulk',
  imports: [MatButtonModule, MatIconModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'flex flex-wrap gap-2' },
  template: `
    <button
      mat-stroked-button
      type="button"
      [disabled]="working()"
      (click)="invoice()"
      data-testid="delivery-notes-bulk-invoice"
    >
      <mat-icon aria-hidden="true">receipt_long</mat-icon>
      {{ 'delivery_notes.bulk.invoice' | translate }}
    </button>
  `,
})
export class DeliveryNoteBulk {
  readonly companyId = input.required<string>();
  /** The selected notes' ids, in the order they were ticked, a list page at most. */
  readonly ids = input.required<readonly string[]>();
  /** The decimals of the company's currency, which a draft's total is shown with. */
  readonly scale = input<number | null>(null);

  private readonly facade = inject(DeliveryNotesFacade);
  private readonly dialog = inject(MatDialog);
  private readonly feedback = inject(Feedback);
  private readonly router = inject(Router);
  protected readonly working = signal(false);

  protected async invoice(): Promise<void> {
    if (this.working()) return;
    this.working.set(true);
    try {
      await this.run(this.companyId());
    } finally {
      this.working.set(false);
    }
  }

  private async run(companyId: string): Promise<void> {
    const check = await this.facade.invoicingCheck(companyId, this.ids()).catch(() => null);
    if (check === null) {
      this.feedback.failure('delivery_notes.bulk.check_failed');
      return;
    }
    if (check.skipped > 0 && !(await this.goOn(check))) return;
    const taken = check.rows.filter((row) => row.included).map((row) => row.id);
    if (taken.length === 0 || check.customerId === null) return;
    try {
      const target = await this.chooseTarget(companyId, check.customerId, check.establishmentId);
      if (target === null) return;
      const invoiceId = await this.facade.invoiceNotes(
        companyId,
        taken,
        target.draftId ?? undefined,
      );
      await this.router.navigate(['/invoices', invoiceId]);
    } catch (error) {
      const code = error instanceof DeliveryNotesRefused ? error.code : 'network';
      this.feedback.failure(`delivery_notes.errors.${code}`);
    }
  }

  private async goOn(check: InvoicingCheck): Promise<boolean> {
    const go = await firstValueFrom(
      this.dialog
        .open<BulkSkippedDialog, BulkSkippedDialogData, true | null>(BulkSkippedDialog, {
          data: {
            rows: check.rows,
            texts: 'delivery_notes.bulk',
            goKey: 'delivery_notes.bulk.invoice_rest',
            testId: 'delivery-notes-bulk',
          },
          autoFocus: 'dialog',
        })
        .afterClosed(),
    );
    // Closed by Escape or a click outside, it hands back nothing, which is a no.
    return go === true;
  }

  /** A new invoice, unless the customer has drafts in that establishment, when the person picks; null if they left. */
  private async chooseTarget(
    companyId: string,
    customerId: string,
    establishmentId: string | null,
  ): Promise<InvoiceTarget | null> {
    const drafts = await this.facade.draftsToAddTo(companyId, customerId, establishmentId);
    if (drafts.length === 0) return { draftId: null };
    return askInvoiceTarget(this.dialog, drafts, customerId, this.scale() ?? 3);
  }
}
