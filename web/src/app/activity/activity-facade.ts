// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { LatestRequest, requestState } from '../shared/feedback/request-state';
import type { ExportFormat } from '../shared/list/export-address';
import { ActivityApi, ActivityRefused } from './activity-api';
import type { ActivityError, ActivityRow, ActivitySearch } from './activity-types';
import { sessionSignal } from '../shared/session/session-data';

/** The page of the company's journal being read. */
@Injectable({ providedIn: 'root' })
export class ActivityFacade {
  private readonly api = inject(ActivityApi);
  private readonly rowsSignal = sessionSignal<readonly ActivityRow[]>([]);
  private readonly totalSignal = sessionSignal(0);
  private readonly state = requestState<ActivityError>((error) =>
    error instanceof ActivityRefused ? error.code : 'network',
  );
  private readonly pages = new LatestRequest();

  readonly rows = this.rowsSignal.asReadonly();
  /** Whether a page is being read, so the list says it is loading rather than empty. */
  readonly busy = this.state.busy;
  readonly total = this.totalSignal.asReadonly();
  readonly error = this.state.error;
  /** Whether `error` is a read's failure, what a list says in place of its rows; a refused save is not. */
  readonly readFailed = this.state.readFailed;
  readonly readRefusedFilter = this.state.readRefusedFilter;

  exportUrl(companyId: string, search: ActivitySearch, format: ExportFormat): string {
    return this.api.exportUrl(companyId, search, format);
  }

  /** Only the latest search's answer is shown: an earlier one answering late would put back what the words left out. */
  async loadPage(companyId: string, search: ActivitySearch): Promise<void> {
    const current = this.pages.next();
    await this.state.read(
      async () => {
        const page = await this.api.entries(companyId, search);
        if (!current()) return;
        this.rowsSignal.set(page.rows);
        this.totalSignal.set(page.total);
      },
      { current },
    );
  }
}
