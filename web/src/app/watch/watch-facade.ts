// SPDX-License-Identifier: AGPL-3.0-or-later

import { DestroyRef, DOCUMENT, inject, Injectable } from '@angular/core';
import { WatchApi } from './watch-api';
import {
  WatchSubjectGone,
  type WatchError,
  type WatchRowPage,
  type WatchSummary,
} from './watch-types';
import { SessionData, sessionSignal } from '../shared/session/session-data';

/** The longest a summary waits to be read again: a moment past it is read again then, and its answer says the next. */
const AT_MOST = 24 * 60 * 60 * 1000;

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
  private readonly summarySignal = sessionSignal<WatchSummary | null>(null);
  private readonly errorSignal = sessionSignal<WatchError | null>(null);
  private readonly rowsSignal = sessionSignal<WatchRowsState>({ status: 'loading' });

  readonly summary = this.summarySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();
  readonly rows = this.rowsSignal.asReadonly();

  private readonly document = inject(DOCUMENT);
  private timer: ReturnType<typeof setTimeout> | null = null;
  private due: { readonly companyId: string; readonly at: number } | null = null;
  /** Moved on each time the session leaves its company: an answer asked before then is another company's. */
  private session = 0;

  constructor() {
    const destroyRef = inject(DestroyRef);
    inject(SessionData).keep(
      () => {
        this.session += 1;
        this.disarm();
      },
      'company',
      destroyRef,
    );
    // A hidden tab's timers are slowed or held by the browser: shown again past the moment, it reads at once.
    const shown = () => {
      const due = this.due;
      if (this.document.visibilityState !== 'hidden' && due !== null && Date.now() >= due.at)
        void this.load(due.companyId);
    };
    this.document.addEventListener('visibilitychange', shown);
    destroyRef.onDestroy(() => {
      this.document.removeEventListener('visibilitychange', shown);
      this.disarm();
    });
  }

  /**
   * Reads the summary again; what was shown stays until the answer replaces it, so a reload does not flicker. Each
   * answer sets the one timer for when it says to read again, whoever asked.
   */
  async load(companyId: string): Promise<void> {
    const session = this.session;
    try {
      const summary = await this.api.summary(companyId);
      // Neither shown nor read again once the session has left that company: it would renew itself on a quiet screen.
      if (session !== this.session) return;
      this.summarySignal.set(summary);
      this.errorSignal.set(null);
      this.arm(companyId, summary.readAgainIn * 1000);
    } catch {
      if (session === this.session) this.errorSignal.set('unavailable');
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

  /** The moment is this browser's own, counted from the answer, so a clock set otherwise than the server's moves nothing. */
  private arm(companyId: string, inMs: number): void {
    this.disarm();
    this.due = { companyId, at: Date.now() + inMs };
    this.timer = setTimeout(() => void this.load(companyId), Math.min(inMs, AT_MOST));
  }

  private disarm(): void {
    if (this.timer !== null) clearTimeout(this.timer);
    this.timer = null;
    this.due = null;
  }
}
