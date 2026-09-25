// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type { WatchWatchRead } from '../api/types.gen';
import type { WatchList } from './watch-types';

/** The one importer of the generated types for « À surveiller ». */
@Injectable({ providedIn: 'root' })
export class WatchApi {
  private readonly http = inject(HttpClient);

  async list(companyId: string): Promise<WatchList> {
    const raw = await firstValueFrom(
      this.http.get<WatchWatchRead>(`/api/companies/${encodeURIComponent(companyId)}/watch`),
    );
    return {
      count: raw.count ?? 0,
      items: (raw.items ?? []).map((item) => ({
        kind: item.kind,
        subjectId: item.subjectId,
        params: item.params,
      })),
    };
  }
}
