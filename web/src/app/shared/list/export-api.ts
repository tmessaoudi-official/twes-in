// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';

/**
 * A list's file, fetched by the page rather than followed as a link, so a refusal can be answered in the page: the API
 * answers `step_up_required` until the person proved who they are in the last few minutes (docs/SPEC.md § 7, audit H-b2).
 */
@Injectable({ providedIn: 'root' })
export class ExportApi {
  private readonly http = inject(HttpClient);

  /** The file, `step_up` when the API wants the proof first, or `failed` for anything else. */
  async file(address: string): Promise<Blob | 'step_up' | 'failed'> {
    try {
      return await firstValueFrom(this.http.get(address, { responseType: 'blob' }));
    } catch (error) {
      return (await stepUpAsked(error)) ? 'step_up' : 'failed';
    }
  }
}

async function stepUpAsked(error: unknown): Promise<boolean> {
  if (
    !(error instanceof HttpErrorResponse) ||
    error.status !== 403 ||
    !(error.error instanceof Blob)
  ) {
    return false;
  }
  try {
    const body: unknown = JSON.parse(await error.error.text());
    return (
      typeof body === 'object' &&
      body !== null &&
      (body as { error?: unknown }).error === 'step_up_required'
    );
  } catch {
    // A 403 whose body is not JSON is not the step-up's.
    return false;
  }
}
