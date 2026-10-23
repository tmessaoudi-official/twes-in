// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import type { ExportFormat } from '../shared/list/export-address';
import { ReportsApi } from './reports-api';
import {
  ReportGone,
  ReportRefused,
  type ReportDocuments,
  type ReportEntry,
  type ReportQuery,
  type ReportResult,
} from './reports-types';
import { sessionSignal } from '../shared/session/session-data';

/**
 * A report being read. `gone` is a report nobody here may read, of a module switched off, or that does not exist;
 * `refused` a question the API cannot answer, such as a period of more than a year.
 */
export type ReportState =
  | { readonly status: 'loading' }
  | { readonly status: 'ready'; readonly result: ReportResult }
  | { readonly status: 'gone' }
  | { readonly status: 'refused' }
  | { readonly status: 'unavailable' };

/** The reports as signals: the catalogue the area offers, and the report on screen. */
@Injectable({ providedIn: 'root' })
export class ReportsFacade {
  private readonly api = inject(ReportsApi);
  private readonly catalogueSignal = sessionSignal<readonly ReportEntry[] | null>(null);
  private readonly catalogueFailedSignal = sessionSignal(false);
  private readonly reportSignal = sessionSignal<ReportState>({ status: 'loading' });
  /** The latest report asked: an answer to an earlier question, slower than the next, is not shown over it. */
  private asked = 0;

  readonly catalogue = this.catalogueSignal.asReadonly();
  readonly catalogueFailed = this.catalogueFailedSignal.asReadonly();
  readonly report = this.reportSignal.asReadonly();

  /** Reads the catalogue again; what was shown stays until the answer replaces it. */
  async loadCatalogue(companyId: string): Promise<void> {
    try {
      this.catalogueSignal.set(await this.api.catalogue(companyId));
      this.catalogueFailedSignal.set(false);
    } catch {
      this.catalogueFailedSignal.set(true);
    }
  }

  /** Reads a report as asked; the report shown stays until the answer replaces it, so a reload does not flicker. */
  async loadReport(companyId: string, key: string, query: ReportQuery): Promise<void> {
    const mine = ++this.asked;
    let state: ReportState;
    try {
      state = { status: 'ready', result: await this.api.report(companyId, key, query) };
    } catch (error) {
      state = {
        status:
          error instanceof ReportGone
            ? 'gone'
            : error instanceof ReportRefused
              ? 'refused'
              : 'unavailable',
      };
    }
    if (mine === this.asked) this.reportSignal.set(state);
  }

  /** Forgets the report shown, so another report never opens on the last one's figures. */
  forgetReport(): void {
    this.asked++;
    this.reportSignal.set({ status: 'loading' });
  }

  documents(
    companyId: string,
    key: string,
    query: ReportQuery,
    row: string,
  ): Promise<ReportDocuments> {
    return this.api.documents(companyId, key, query, row);
  }

  exportAddress(companyId: string, key: string, query: ReportQuery, format: ExportFormat): string {
    return this.api.exportAddress(companyId, key, query, format);
  }
}
