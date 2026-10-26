// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { type FormAnswer, requestState } from '../shared/feedback/request-state';
import { InstrumentsApi } from './instruments-api';
import type { InstrumentInput, InstrumentRow, InstrumentStep } from './instruments-types';
import { InvoicesRefused } from './invoices-api';
import type { InvoicesError } from './invoices-types';

/**
 * The cheques and traites of the one invoice a panel shows, so each panel has its own (provided by the component, not
 * the root). A step that works re-reads the list: the API is what says a cheque is cashed.
 */
@Injectable()
export class InstrumentsFacade {
  private readonly api = inject(InstrumentsApi);

  private readonly itemsSignal = signal<readonly InstrumentRow[]>([]);
  private readonly state = requestState<InvoicesError>((error) =>
    error instanceof InvoicesRefused ? error.code : 'network',
  );

  readonly items = this.itemsSignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;

  async load(companyId: string, invoiceId: string): Promise<void> {
    await this.run(async () => {
      this.itemsSignal.set(await this.api.list(companyId, invoiceId));
    }, true);
  }

  /** The cheque or traite received, the list read again; a refusal naming fields is the form's to say under them. */
  async receive(
    companyId: string,
    invoiceId: string,
    input: InstrumentInput,
  ): Promise<FormAnswer<void>> {
    return this.state.writeOrRefuse(
      async () => {
        await this.api.receive(companyId, invoiceId, input);
        this.itemsSignal.set(await this.api.list(companyId, invoiceId));
      },
      (error) => (error instanceof InvoicesRefused ? error.refusals : []),
    );
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

  clearError(): void {
    this.state.clearError();
  }

  /** `reading` marks a read: one nobody asked for leaves busy as it is (QuietReads); a write is always marked. */
  private async run(work: () => Promise<void>, reading = false): Promise<boolean> {
    return reading ? this.state.read(work) : this.state.write(work);
  }
}
