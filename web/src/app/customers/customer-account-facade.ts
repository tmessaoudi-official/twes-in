// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { CustomersApi, CustomersRefused } from './customers-api';
import type { CustomerAccount, CustomersError } from './customers-types';

/** The customer's running account as it stands today, or why it is not there. */
@Injectable({ providedIn: 'root' })
export class CustomerAccountFacade {
  private readonly api = inject(CustomersApi);
  private readonly accountSignal = signal<CustomerAccount | null>(null);
  private readonly errorSignal = signal<CustomersError | null>(null);
  private request = 0;

  readonly account = this.accountSignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  /** Reads the account; only the latest customer's answer is kept, an earlier one answering late is dropped. */
  async load(companyId: string, customerId: string): Promise<void> {
    const request = ++this.request;
    this.errorSignal.set(null);
    try {
      const account = await this.api.account(companyId, customerId);
      if (request === this.request) this.accountSignal.set(account);
    } catch (error) {
      if (request !== this.request) return;
      this.accountSignal.set(null);
      this.errorSignal.set(error instanceof CustomersRefused ? error.code : 'network');
    }
  }
}
