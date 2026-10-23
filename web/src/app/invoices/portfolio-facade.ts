// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { LatestRequest, requestState } from '../shared/feedback/request-state';
import { InvoicesRefused } from './invoices-api';
import type { InvoicesError } from './invoices-types';
import { PortfolioApi } from './portfolio-api';
import type { PortfolioRow, PortfolioSearch } from './portfolio-types';
import { sessionSignal } from '../shared/session/session-data';

/** The page of the company's cheques and traites the portfolio screen shows. */
@Injectable({ providedIn: 'root' })
export class PortfolioFacade {
  private readonly api = inject(PortfolioApi);

  private readonly rowsSignal = sessionSignal<readonly PortfolioRow[]>([]);
  private readonly totalSignal = sessionSignal(0);
  private readonly state = requestState<InvoicesError>((error) =>
    error instanceof InvoicesRefused ? error.code : 'network',
  );
  private readonly pages = new LatestRequest();

  readonly rows = this.rowsSignal.asReadonly();
  /** Whether a page is being read, so the list says it is loading rather than empty. */
  readonly busy = this.state.busy;
  /** How many instruments the last search found in all, the page shown being one part of them. */
  readonly total = this.totalSignal.asReadonly();
  readonly error = this.state.error;

  /** One page of the search. Only the latest search's answer is shown: pages may come back out of order. */
  async loadPage(companyId: string, search: PortfolioSearch): Promise<void> {
    const current = this.pages.next();
    await this.state.read(
      async () => {
        const page = await this.api.portfolio(companyId, search);
        if (!current()) return;
        this.rowsSignal.set(page.rows);
        this.totalSignal.set(page.total);
      },
      { current },
    );
  }
}
