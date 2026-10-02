// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { CustomersApi, CustomersRefused } from './customers-api';
import type { CreditDepositInput, CustomersError, CustomerStatement } from './customers-types';

/** The statement of account being read: the customer's account over the period asked for, or why it is not there. */
@Injectable({ providedIn: 'root' })
export class CustomerStatementFacade {
  private readonly api = inject(CustomersApi);
  private readonly statementSignal = signal<CustomerStatement | null>(null);
  private readonly errorSignal = signal<CustomersError | null>(null);
  private readonly busySignal = signal(false);
  private request = 0;

  readonly statement = this.statementSignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();

  /**
   * Reads the account. Only the latest question's answer is shown: changing a day sends a request, and an earlier one
   * answering late would put back a period nobody asked for any more.
   */
  async load(
    companyId: string,
    customerId: string,
    period: { from?: string; to?: string },
  ): Promise<void> {
    const request = ++this.request;
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      const statement = await this.api.statement(companyId, customerId, period);
      if (request === this.request) this.statementSignal.set(statement);
    } catch (error) {
      if (request !== this.request) return;
      this.statementSignal.set(null);
      this.errorSignal.set(error instanceof CustomersRefused ? error.code : 'network');
    } finally {
      if (request === this.request) this.busySignal.set(false);
    }
  }

  /** True once recorded; the refusal, if any, is in `error`. The statement is read again by whoever asked. */
  async deposit(
    companyId: string,
    customerId: string,
    deposit: CreditDepositInput,
  ): Promise<boolean> {
    this.errorSignal.set(null);
    try {
      await this.api.depositCredit(companyId, customerId, deposit);
      return true;
    } catch (error) {
      this.errorSignal.set(error instanceof CustomersRefused ? error.code : 'network');
      return false;
    }
  }

  pdfUrl(companyId: string, customerId: string, period: { from?: string; to?: string }): string {
    return this.api.statementPdfUrl(companyId, customerId, period);
  }
}
