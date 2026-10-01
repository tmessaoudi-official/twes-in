// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { WatchApi } from './watch-api';
import {
  WatchSubjectGone,
  type WatchError,
  type WatchRowPage,
  type WatchSummary,
} from './watch-types';

/** The page of a subject being read; `gone` is a subject that has left the list, or that nobody here may see. */
export type WatchRowsState =
  | { readonly status: 'loading' }
  | { readonly status: 'ready'; readonly page: WatchRowPage }
  | { readonly status: 'gone' }
  | { readonly status: 'unavailable' };

/** « À surveiller » as signals: the home's count, the overview and a subject's page read the same summary. */
@Injectable({ providedIn: 'root' })
export class WatchFacade {
  private readonly api = inject(WatchApi);
  private readonly summarySignal = signal<WatchSummary | null>(null);
  private readonly errorSignal = signal<WatchError | null>(null);
  private readonly rowsSignal = signal<WatchRowsState>({ status: 'loading' });

  readonly summary = this.summarySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();
  readonly rows = this.rowsSignal.asReadonly();

  /** Reads the summary again; what was shown stays until the answer replaces it, so a reload does not flicker. */
  async load(companyId: string): Promise<void> {
    try {
      this.summarySignal.set(await this.api.summary(companyId));
      this.errorSignal.set(null);
    } catch {
      this.errorSignal.set('unavailable');
    }
  }

  /** Reads one page of a subject; the page shown stays until the answer replaces it. */
  async loadRows(
    companyId: string,
    kind: string,
    pageIndex: number,
    pageSize: number,
  ): Promise<void> {
    try {
      this.rowsSignal.set({
        status: 'ready',
        page: await this.api.rows(companyId, kind, pageIndex, pageSize),
      });
    } catch (error) {
      // 404 is the API's one answer for a subject that is not there: dealt with, switched off or not for this role.
      this.rowsSignal.set({
        status: error instanceof WatchSubjectGone ? 'gone' : 'unavailable',
      });
    }
  }
}
