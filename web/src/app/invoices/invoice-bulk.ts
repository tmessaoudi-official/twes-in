// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { MatDialog } from '@angular/material/dialog';
import { firstValueFrom } from 'rxjs';
import { Feedback } from '../shared/feedback/feedback';
import type { ExportFormat } from '../shared/list/export-address';
import { ListExport } from '../shared/list/list-export';
import { InvoiceBulkSkippedDialog, type BulkSkippedData } from './invoice-bulk-skipped-dialog';
import { InvoicesFacade } from './invoices-facade';

/**
 * What the invoices list offers on the rows a person selected, in its selection bar. Each action asks the API first what
 * it would do with every one of them, goes straight on when all of them go in, and otherwise names each one left out
 * and why before doing the rest, so a row deleted meanwhile is never silently missing from what was asked for.
 */
@Component({
  selector: 'app-invoice-bulk',
  imports: [ListExport],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <app-list-export
      [address]="exporter()"
      [prepare]="checkExport"
      (saved)="exported()"
      testId="invoices-bulk-export"
    />
  `,
})
export class InvoiceBulk {
  readonly companyId = input.required<string>();
  /** The selected invoices' ids, a list page at most. */
  readonly ids = input.required<readonly string[]>();

  private readonly facade = inject(InvoicesFacade);
  private readonly dialog = inject(MatDialog);
  private readonly feedback = inject(Feedback);
  /** How many invoices the file being fetched holds, as the API last checked. */
  private count = 0;

  protected readonly exporter = computed(() => {
    const companyId = this.companyId();
    const ids = this.ids();
    return (format: ExportFormat) => this.facade.selectedExportUrl(companyId, ids, format);
  });

  /**
   * Whether the file is fetched. An id the API no longer finds is simply not in the file, so once the person has been
   * told, the same address is fetched for the rest.
   */
  protected readonly checkExport = async (address: string): Promise<string | null> => {
    const check = await this.facade
      .bulkCheck(this.companyId(), 'export', this.ids())
      .catch(() => null);
    if (check === null) {
      this.feedback.failure('invoices.bulk.check_failed');
      return null;
    }
    this.count = check.included;
    if (check.skipped === 0) return address;
    const data: BulkSkippedData = { check, goKey: 'invoices.bulk.export_rest' };
    const go = await firstValueFrom(
      this.dialog.open(InvoiceBulkSkippedDialog, { data, autoFocus: 'dialog' }).afterClosed(),
    );
    return go === true ? address : null;
  };

  protected exported(): void {
    this.feedback.success('invoices.bulk.exported', { count: this.count });
  }
}
