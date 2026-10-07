// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse, HttpHeaders } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type { Health } from '../../api/types.gen';
import type { ApiBuild, PartBuild } from './build-types';

/** Asked of the server every time, whatever a cache in between would answer: the point is to learn of a new one. */
const FRESH = new HttpHeaders({ 'Cache-Control': 'no-cache' });

/** The two places a build is said: `/version.json` beside the bundle, and `/api/health`. Null when nobody answered. */
@Injectable({ providedIn: 'root' })
export class BuildApi {
  private readonly http = inject(HttpClient);

  /** The web build the server holds now, which may be newer than the page running; unversioned when there is no file. */
  async web(): Promise<PartBuild | null> {
    try {
      const body = await firstValueFrom(
        this.http.get<Partial<PartBuild>>('/version.json', { headers: FRESH }),
      );
      return { version: given(body.version), commit: given(body.commit) };
    } catch (error) {
      return error instanceof HttpErrorResponse && error.status === 404
        ? { version: null, commit: null }
        : null;
    }
  }

  /** The API's build; a degraded API (503) still says it. */
  async api(): Promise<ApiBuild | null> {
    let body: Partial<Health> | null;
    try {
      body = await firstValueFrom(this.http.get<Health>('/api/health', { headers: FRESH }));
    } catch (error) {
      body = error instanceof HttpErrorResponse ? (error.error as Partial<Health> | null) : null;
    }
    const build = body?.build;
    if (!build) return null;
    return {
      version: given(build.version),
      commit: given(build.commit),
      mode: build.mode,
      deployment: given(body?.deployment),
    };
  }
}

function given(value: string | null | undefined): string | null {
  return typeof value === 'string' && value !== '' ? value : null;
}
