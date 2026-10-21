// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { LatestRequest, requestState } from '../shared/feedback/request-state';
import { CustomersApi, CustomersRefused } from './customers-api';
import type { CustomersError, CustomerStatement } from './customers-types';

/** The statement of account being read: the customer's account over the period asked for, or why it is not there. */
@Injectable({ providedIn: 'root' })
export class CustomerStatementFacade {
  private readonly api = inject(CustomersApi);
  private readonly statementSignal = signal<CustomerStatement | null>(null);
  private readonly state = requestState<CustomersError>((error) =>
    error instanceof CustomersRefused ? error.code : 'network',
  );
  private readonly requests = new LatestRequest();

  readonly statement = this.statementSignal.asReadonly();
  readonly error = this.state.error;
  readonly busy = this.state.busy;

  /**
   * Reads the account. Only the latest question's answer is shown: changing a day sends a request, and an earlier one
   * answering late would put back a period nobody asked for any more.
   */
  async load(
    companyId: string,
    customerId: string,
    period: { from?: string; to?: string },
  ): Promise<void> {
    const current = this.requests.next();
    await this.state.read(
      async () => {
        const statement = await this.api.statement(companyId, customerId, period);
        if (current()) this.statementSignal.set(statement);
      },
      { current, failed: () => this.statementSignal.set(null) },
    );
  }

  pdfUrl(companyId: string, customerId: string, period: { from?: string; to?: string }): string {
    return this.api.statementPdfUrl(companyId, customerId, period);
  }
}
