// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom, type Observable } from 'rxjs';
import type {
  ReportCatalogueReportCatalogueRead,
  ReportDocumentsReportDocumentsRead,
  ReportReportRead,
} from '../api/types.gen';
import type { ExportFormat } from '../shared/list/export-address';
import {
  ReportGone,
  ReportRefused,
  type ReportDocuments,
  type ReportEntry,
  type ReportQuery,
  type ReportResult,
} from './reports-types';
import { refusedFor } from '../shared/errors/said-already';

/** The one importer of the generated types for the reports. */
@Injectable({ providedIn: 'root' })
export class ReportsApi {
  private readonly http = inject(HttpClient);

  /** The reports the member may read in the company, in the order the area offers them. */
  async catalogue(companyId: string): Promise<readonly ReportEntry[]> {
    const raw = await firstValueFrom(
      this.http.get<ReportCatalogueReportCatalogueRead>(reportsPath(companyId)),
    );
    return (raw.reports ?? []).map((report) => ({
      key: report.key,
      byEstablishment: report.byEstablishment,
      periodic: report.periodic,
      columns: report.columns,
    }));
  }

  /** One report as asked; a report nobody here may read is `ReportGone`, a question it cannot answer `ReportRefused`. */
  async report(companyId: string, key: string, query: ReportQuery): Promise<ReportResult> {
    const raw = await this.read(
      this.http.get<ReportReportRead>(`${reportsPath(companyId)}/${encodeURIComponent(key)}`, {
        params: paramsOf(query),
      }),
    );
    return {
      key: raw.key,
      from: raw.from,
      to: raw.to,
      comparisonFrom: raw.comparisonFrom,
      comparisonTo: raw.comparisonTo,
      columns: raw.columns,
      rows: raw.rows,
      totals: raw.totals,
      previousTotals: raw.previousTotals,
    };
  }

  /** The documents behind one row, read over the period the report was. */
  async documents(
    companyId: string,
    key: string,
    query: ReportQuery,
    row: string,
  ): Promise<ReportDocuments> {
    const raw = await this.read(
      this.http.get<ReportDocumentsReportDocumentsRead>(
        `${reportsPath(companyId)}/${encodeURIComponent(key)}/documents`,
        { params: paramsOf({ ...query, compare: 'none' }).set('row', row) },
      ),
    );
    return { documents: raw.documents, more: raw.more };
  }

  /** The report as a file, under the period, comparison and establishment the screen shows. */
  exportAddress(companyId: string, key: string, query: ReportQuery, format: ExportFormat): string {
    const search = paramsOf(query).toString();
    return `${reportsPath(companyId)}/${encodeURIComponent(key)}/export.${format}${search === '' ? '' : `?${search}`}`;
  }

  private async read<T>(request: Observable<T>): Promise<T> {
    try {
      return await firstValueFrom(request);
    } catch (error) {
      if (error instanceof HttpErrorResponse && error.status === 404) throw new ReportGone();
      if (error instanceof HttpErrorResponse && error.status === 422)
        throw refusedFor(error, new ReportRefused());
      throw error;
    }
  }
}

function reportsPath(companyId: string): string {
  return `/api/companies/${encodeURIComponent(companyId)}/reports`;
}

/** What is asked, as the API reads it: no period for a report of today, no comparison when there is none. */
function paramsOf(query: ReportQuery): HttpParams {
  let params = new HttpParams();
  if (query.from !== null) params = params.set('from', query.from);
  if (query.to !== null) params = params.set('to', query.to);
  if (query.compare !== 'none') params = params.set('compare', query.compare);
  if (query.establishment !== null) params = params.set('establishment', query.establishment);
  return params;
}
