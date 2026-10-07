// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  ActivityJsonldActivityRead,
  ApiCompaniesCompanyIdactivityGetCollectionResponse,
} from '../api/types.gen';
import { type ExportFormat, exportAddress } from '../shared/list/export-address';
import { apiRangeKey } from '../shared/list/list-filters';
import type { ListPage } from '../shared/list/list-types';
import type { ActivityError, ActivityRow, ActivitySearch } from './activity-types';

/** Thrown when the API refuses; carries the code the UI translates. */
export class ActivityRefused extends Error {
  constructor(readonly code: ActivityError) {
    super(code);
  }
}

/** The HTTP edge of the activity journal: the only code here that knows its endpoint and generated types. */
@Injectable({ providedIn: 'root' })
export class ActivityApi {
  private readonly http = inject(HttpClient);

  /** One page of the journal, as Hydra carries it: the rows and the total. */
  async entries(companyId: string, search: ActivitySearch): Promise<ListPage<ActivityRow>> {
    try {
      const page = await firstValueFrom(
        this.http.get<ApiCompaniesCompanyIdactivityGetCollectionResponse>(
          `/api/companies/${encodeURIComponent(companyId)}/activity`,
          { headers: { Accept: 'application/ld+json' }, params: toSearchParams(search) },
        ),
      );
      if (page.totalItems === undefined)
        throw new Error('A page of the journal came without its total.');
      return { rows: page.member.map(toRow), total: page.totalItems };
    } catch (error) {
      throw new ActivityRefused(codeOf(error));
    }
  }

  /** Where the entries the search finds are downloaded as a file, every page of them. */
  exportUrl(companyId: string, search: ActivitySearch, format: ExportFormat): string {
    return exportAddress(companyId, 'activity', toSearchParams(search), format);
  }
}

function codeOf(error: unknown): ActivityError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) return 'network';
  return error.status === 404 ? 'not_found' : 'invalid';
}

export function toSearchParams(search: ActivitySearch): HttpParams {
  let params = new HttpParams().set('page', search.page).set('itemsPerPage', search.itemsPerPage);
  if (search.q.trim() !== '') params = params.set('q', search.q.trim());
  for (const id of search.actorIds) params = params.append('actorId[]', id);
  for (const type of search.entityTypes) params = params.append('entityType[]', type);
  if (search.entityId !== null) params = params.set('entityId', search.entityId);
  for (const [key, value] of Object.entries(search.intervals)) {
    params = params.set(apiRangeKey(key), value);
  }
  if (search.direction === 'asc') params = params.set('order[at]', 'asc');
  return params;
}

function toRow(raw: ActivityJsonldActivityRead): ActivityRow {
  return {
    id: raw.id ?? '',
    at: raw.at,
    action: raw.action,
    entityType: raw.entityType,
    entityId: raw.entityId,
    actorId: raw.actorId,
    actorName: raw.actorName,
    fields: raw.fields,
    ip: raw.ip,
  };
}
