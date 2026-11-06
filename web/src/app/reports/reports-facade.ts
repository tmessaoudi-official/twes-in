// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, Injector } from '@angular/core';
import type { FieldRefusal } from '../shared/form/field-refusals';
import { FORM_REFUSAL_TEXTS } from '../shared/form/form-refusal-texts';
import { TextsParts } from '../shared/i18n/texts-part';
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
 * `refused` a question the API cannot answer, such as a period of more than a year, with the reason it gave, which tells
 * what to change.
 */
export type ReportState =
  | { readonly status: 'loading' }
  | { readonly status: 'ready'; readonly result: ReportResult }
  | { readonly status: 'gone' }
  | { readonly status: 'refused'; readonly refusal: FieldRefusal | null }
  | { readonly status: 'unavailable' };

/** The reports as signals: the catalogue the area offers, and the report on screen. */
@Injectable({ providedIn: 'root' })
export class ReportsFacade {
  private readonly api = inject(ReportsApi);
  private readonly injector = inject(Injector);
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
      state =
        error instanceof ReportGone
          ? { status: 'gone' }
          : error instanceof ReportRefused
            ? { status: 'refused', refusal: await this.worded(error) }
            : { status: 'unavailable' };
    }
    if (mine === this.asked) this.reportSignal.set(state);
  }

  /** The reason the API worded, its texts opened first; were they not to come, the page's own sentence stands. */
  private async worded(refused: ReportRefused): Promise<FieldRefusal | null> {
    const refusal = refused.refusals.find((one) => one.reason !== null) ?? null;
    if (refusal !== null)
      await this.injector
        .get(TextsParts)
        .open(FORM_REFUSAL_TEXTS)
        .catch(() => undefined);
    return refusal;
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
