// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import type { ExportFormat } from '../shared/list/export-address';
import { ActivityApi, ActivityRefused } from './activity-api';
import type { ActivityError, ActivityRow, ActivitySearch } from './activity-types';

/** The page of the company's journal being read. */
@Injectable({ providedIn: 'root' })
export class ActivityFacade {
  private readonly api = inject(ActivityApi);
  private readonly rowsSignal = signal<readonly ActivityRow[]>([]);
  private readonly totalSignal = signal(0);
  private readonly errorSignal = signal<ActivityError | null>(null);
  private readonly busySignal = signal(false);
  private request = 0;

  readonly rows = this.rowsSignal.asReadonly();
  /** Whether a page is being read, so the list says it is loading rather than empty. */
  readonly busy = this.busySignal.asReadonly();
  readonly total = this.totalSignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  exportUrl(companyId: string, search: ActivitySearch, format: ExportFormat): string {
    return this.api.exportUrl(companyId, search, format);
  }

  /** Only the latest search's answer is shown: an earlier one answering late would put back what the words left out. */
  async loadPage(companyId: string, search: ActivitySearch): Promise<void> {
    const request = ++this.request;
    this.busySignal.set(true);
    try {
      const page = await this.api.entries(companyId, search);
      if (request !== this.request) return;
      this.rowsSignal.set(page.rows);
      this.totalSignal.set(page.total);
      this.errorSignal.set(null);
    } catch (error) {
      if (request !== this.request) return;
      this.errorSignal.set(error instanceof ActivityRefused ? error.code : 'network');
    } finally {
      if (request === this.request) this.busySignal.set(false);
    }
  }
}
