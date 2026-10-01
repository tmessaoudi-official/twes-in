// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  ApiCompaniesCompanyIdwatchKindGetCollectionResponse,
  WatchWatchRead,
} from '../api/types.gen';
import { WatchSubjectGone, type WatchRowPage, type WatchSummary } from './watch-types';

/** The one importer of the generated types for « À surveiller ». */
@Injectable({ providedIn: 'root' })
export class WatchApi {
  private readonly http = inject(HttpClient);

  /** Every subject with something in it, and the whole count: no row is read. */
  async summary(companyId: string): Promise<WatchSummary> {
    const raw = await firstValueFrom(
      this.http.get<WatchWatchRead>(`/api/companies/${encodeURIComponent(companyId)}/watch`),
    );
    return {
      count: raw.count ?? 0,
      subjects: (raw.subjects ?? []).map((subject) => ({
        kind: subject.kind,
        count: subject.count,
      })),
    };
  }

  /** One page of one subject, numbered from 0 as the paginator counts. A subject nobody may see answers 404. */
  async rows(
    companyId: string,
    kind: string,
    pageIndex: number,
    pageSize: number,
  ): Promise<WatchRowPage> {
    const page = await firstValueFrom(
      this.http.get<ApiCompaniesCompanyIdwatchKindGetCollectionResponse>(
        `/api/companies/${encodeURIComponent(companyId)}/watch/${encodeURIComponent(kind)}`,
        {
          headers: { Accept: 'application/ld+json' },
          params: new HttpParams().set('page', pageIndex + 1).set('itemsPerPage', pageSize),
        },
      ),
    ).catch((error: unknown) => {
      throw error instanceof HttpErrorResponse && error.status === 404
        ? new WatchSubjectGone()
        : error;
    });
    if (page.totalItems === undefined)
      throw new Error('A page of a watch subject came without its total.');
    return {
      rows: page.member.map((row) => ({
        kind: row.kind,
        subjectId: row.subjectId,
        params: row.params,
      })),
      total: page.totalItems,
    };
  }
}
