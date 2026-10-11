// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, input, output } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { firstValueFrom } from 'rxjs';
import { Feedback } from '../shared/feedback/feedback';
import { FormatFacade } from '../shared/i18n/format-facade';
import { todayIn } from '../shared/i18n/format';
import type { ExportFormat } from '../shared/list/export-address';
import { ListExport } from '../shared/list/list-export';
import { InvoiceBulkPayDialog, type BulkPayData } from './invoice-bulk-pay-dialog';
import {
  InvoiceBulkSkippedDialog,
  type BulkSkippedChoice,
  type BulkSkippedData,
} from './invoice-bulk-skipped-dialog';
import { InvoicesFacade } from './invoices-facade';
import type { BulkAction, BulkCheck, BulkPaid, BulkPdfFormat } from './invoices-types';

/**
 * What the invoices list offers on the rows a person selected, in its selection bar. Each action asks the API first what
 * it would do with every one of them, goes straight on when all of them go in, and otherwise names each one left out
 * and why before doing the rest, so a row deleted meanwhile is never silently missing from what was asked for.
 */
@Component({
  selector: 'app-invoice-bulk',
  imports: [ListExport, MatButtonModule, MatIconModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'flex flex-wrap gap-2' },
  template: `
    <app-list-export
      [address]="exporter()"
      [prepare]="checkExport"
      (saved)="exported()"
      testId="invoices-bulk-export"
    />
    <app-list-export
      [address]="printer()"
      [formats]="PDF_FORMATS"
      label="invoices.bulk.pdf"
      entries="invoices.bulk.pdf_as"
      icon="picture_as_pdf"
      [prepare]="checkPdf"
      (saved)="printed()"
      testId="invoices-bulk-pdf"
    />
    @if (mayPay()) {
      <button mat-stroked-button type="button" (click)="pay()" data-testid="invoices-bulk-pay">
        <mat-icon aria-hidden="true">payments</mat-icon>
        {{ 'invoices.bulk.pay' | translate }}
      </button>
    }
  `,
})
export class InvoiceBulk {
  readonly companyId = input.required<string>();
  /** The selected invoices' ids, a list page at most. */
  readonly ids = input.required<readonly string[]>();
  /** Whether the member may record payments (`payment.write`), which « Encaisser » does. */
  readonly mayPay = input(false);
  /** The currency's decimals, which amounts are shown with. */
  readonly scale = input<number | null>(null);
  /** The company's time zone, whose day a payment is dated by default. */
  readonly timeZone = input<string | null>(null);
  /** Something on the selected invoices changed, which the list shows again. */
  readonly changed = output<void>();

  private readonly facade = inject(InvoicesFacade);
  private readonly dialog = inject(MatDialog);
  private readonly feedback = inject(Feedback);
  private readonly format = inject(FormatFacade);
  protected readonly PDF_FORMATS: readonly BulkPdfFormat[] = ['pdf', 'zip'];
  /** How many invoices the file being fetched holds, as the API last checked. */
  private count = 0;

  protected readonly exporter = computed(() => {
    const companyId = this.companyId();
    const ids = this.ids();
    return (format: ExportFormat) => this.facade.selectedExportUrl(companyId, ids, format);
  });

  protected readonly printer = computed(() => {
    const companyId = this.companyId();
    const ids = this.ids();
    return (format: BulkPdfFormat) => this.facade.selectedPdfUrl(companyId, ids, format);
  });

  /**
   * Whether the file is fetched. An id the API no longer finds is simply not in the file, so once the person has been
   * told, the same address is fetched for the rest.
   */
  protected readonly checkExport = async (address: string): Promise<string | null> => {
    const check = await this.check('export');
    if (check === null) return null;
    if (check.skipped === 0) return address;
    const choice = await this.ask({ check, goKey: 'invoices.bulk.export_rest' });
    return choice === null ? null : address;
  };

  /**
   * The same for a PDF, which leaves the drafts and cancelled ones out unless the person ticks their box: the address
   * then asks the API for them too, and the API checks them again.
   */
  protected readonly checkPdf = async (address: string): Promise<string | null> => {
    const check = await this.check('pdf');
    if (check === null) return null;
    if (check.skipped === 0) return address;
    const choice = await this.ask({ check, goKey: 'invoices.bulk.pdf_rest', offersBoxes: true });
    if (choice === null) return null;
    const taken = check.rows.filter(
      (row) =>
        (row.reason === 'draft' && choice.drafts) ||
        (row.reason === 'cancelled' && choice.cancelled),
    ).length;
    this.count = check.included + taken;
    return `${address}${choice.drafts ? '&drafts=1' : ''}${choice.cancelled ? '&cancelled=1' : ''}`;
  };

  /** « Encaisser »: the dialog previews, asks for the day and method, and records; the list is read again after. */
  protected async pay(): Promise<void> {
    const data: BulkPayData = {
      companyId: this.companyId(),
      ids: this.ids(),
      today: todayIn(this.timeZone()),
      scale: this.scale(),
    };
    const paid = await firstValueFrom(
      this.dialog
        .open<InvoiceBulkPayDialog, BulkPayData, BulkPaid | null>(InvoiceBulkPayDialog, {
          data,
          autoFocus: 'first-tabbable',
        })
        .afterClosed(),
    );
    if (!paid) return;
    this.feedback.success('invoices.bulk.paid', {
      count: paid.recorded,
      total: this.format.amount(paid.total, this.scale()),
    });
    this.changed.emit();
  }

  protected exported(): void {
    this.feedback.success('invoices.bulk.exported', { count: this.count });
  }

  protected printed(): void {
    this.feedback.success('invoices.bulk.printed', { count: this.count });
  }

  /** What the API would do with each selected invoice, or null once the person is told it could not say. */
  private async check(action: BulkAction): Promise<BulkCheck | null> {
    const check = await this.facade
      .bulkCheck(this.companyId(), action, this.ids())
      .catch(() => null);
    if (check === null) this.feedback.failure('invoices.bulk.check_failed');
    else this.count = check.included;
    return check;
  }

  private async ask(data: BulkSkippedData): Promise<BulkSkippedChoice | null> {
    const choice = await firstValueFrom(
      this.dialog
        .open<InvoiceBulkSkippedDialog, BulkSkippedData, BulkSkippedChoice | null>(
          InvoiceBulkSkippedDialog,
          { data, autoFocus: 'dialog' },
        )
        .afterClosed(),
    );
    // Closed by Escape or a click outside, it hands back nothing, which is a no.
    return choice ?? null;
  }
}
