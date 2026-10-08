// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { InvoicesRefused } from './invoices-api';
import type { InvoicesError } from './invoices-types';
import { PortfolioApi } from './portfolio-api';
import type { PortfolioRow, PortfolioSearch } from './portfolio-types';

/** The page of the company's cheques and traites the portfolio screen shows. */
@Injectable({ providedIn: 'root' })
export class PortfolioFacade {
  private readonly api = inject(PortfolioApi);

  private readonly rowsSignal = signal<readonly PortfolioRow[]>([]);
  private readonly totalSignal = signal(0);
  private readonly errorSignal = signal<InvoicesError | null>(null);
  private readonly busySignal = signal(false);
  private request = 0;

  readonly rows = this.rowsSignal.asReadonly();
  /** Whether a page is being read, so the list says it is loading rather than empty. */
  readonly busy = this.busySignal.asReadonly();
  /** How many instruments the last search found in all, the page shown being one part of them. */
  readonly total = this.totalSignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  /** One page of the search. Only the latest search's answer is shown: pages may come back out of order. */
  async loadPage(companyId: string, search: PortfolioSearch): Promise<void> {
    const request = ++this.request;
    this.busySignal.set(true);
    try {
      const page = await this.api.portfolio(companyId, search);
      if (request !== this.request) return;
      this.rowsSignal.set(page.rows);
      this.totalSignal.set(page.total);
      this.errorSignal.set(null);
    } catch (error) {
      if (request !== this.request) return;
      this.errorSignal.set(error instanceof InvoicesRefused ? error.code : 'network');
    } finally {
      if (request === this.request) this.busySignal.set(false);
    }
  }
}
