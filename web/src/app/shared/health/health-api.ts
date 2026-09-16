// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { catchError, map, Observable, of } from 'rxjs';
import type { Health } from '../../api/types.gen';

export type ApiStatus = 'ok' | 'degraded' | 'unreachable';

/** GET /api/health as the SPA reads it: three words, never an exception. */
@Injectable({ providedIn: 'root' })
export class HealthApi {
  private readonly http = inject(HttpClient);

  status(): Observable<ApiStatus> {
    return this.http.get<Health>('/api/health').pipe(
      map((body): ApiStatus => (body.status === 'ok' ? 'ok' : 'degraded')),
      catchError((error: HttpErrorResponse) =>
        of<ApiStatus>(
          (error.error as Partial<Health> | null)?.status === 'degraded'
            ? 'degraded'
            : 'unreachable',
        ),
      ),
    );
  }
}
