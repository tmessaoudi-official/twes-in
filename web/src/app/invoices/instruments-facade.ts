// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { InstrumentsApi } from './instruments-api';
import type { InstrumentInput, InstrumentRow, InstrumentStep } from './instruments-types';
import { InvoicesRefused } from './invoices-api';
import type { InvoicesError } from './invoices-types';
import { QuietReads } from '../shared/feedback/quiet-reads';

/**
 * The cheques and traites of the one invoice a panel shows, so each panel has its own (provided by the component, not
 * the root). A step that works re-reads the list: the API is what says a cheque is cashed.
 */
@Injectable()
export class InstrumentsFacade {
  private readonly api = inject(InstrumentsApi);
  private readonly quietReads = inject(QuietReads);

  private readonly itemsSignal = signal<readonly InstrumentRow[]>([]);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<InvoicesError | null>(null);

  readonly items = this.itemsSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  async load(companyId: string, invoiceId: string): Promise<void> {
    await this.run(async () => {
      this.itemsSignal.set(await this.api.list(companyId, invoiceId));
    }, true);
  }

  async receive(companyId: string, invoiceId: string, input: InstrumentInput): Promise<boolean> {
    return this.run(async () => {
      await this.api.receive(companyId, invoiceId, input);
      this.itemsSignal.set(await this.api.list(companyId, invoiceId));
    });
  }

  async advance(
    companyId: string,
    invoiceId: string,
    instrumentId: string,
    step: InstrumentStep,
  ): Promise<boolean> {
    return this.run(async () => {
      await this.api.advance(companyId, invoiceId, instrumentId, step);
      this.itemsSignal.set(await this.api.list(companyId, invoiceId));
    });
  }

  async remove(companyId: string, invoiceId: string, instrumentId: string): Promise<boolean> {
    return this.run(async () => {
      await this.api.remove(companyId, invoiceId, instrumentId);
      this.itemsSignal.set(await this.api.list(companyId, invoiceId));
    });
  }

  clearError(): void {
    this.errorSignal.set(null);
  }

  /** `reading` marks a read: one nobody asked for leaves busy as it is (QuietReads); a write is always marked. */
  private async run(work: () => Promise<void>, reading = false): Promise<boolean> {
    const marked = !(reading && this.quietReads.quiet());
    if (marked) this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      await work();
      return true;
    } catch (error) {
      this.errorSignal.set(error instanceof InvoicesRefused ? error.code : 'network');
      return false;
    } finally {
      if (marked) this.busySignal.set(false);
    }
  }
}
