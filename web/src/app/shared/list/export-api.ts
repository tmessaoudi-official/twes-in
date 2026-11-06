// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable, Injector } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { DEMO_REFUSED, isDemoRefused } from '../demo/demo-refused-interceptor';
import type { FieldRefusal } from '../form/field-refusals';
import { formRefusalsIn } from '../form/form-refusals-api';

/** What the API refused the file for (a filter it cannot be made under), which fetching it again would meet again. */
export interface ExportRefused {
  readonly refusals: readonly FieldRefusal[];
}

/**
 * A list's file, fetched by the page rather than followed as a link, so a refusal can be answered in the page: the API
 * answers `step_up_required` until the person proved who they are in the last few minutes (docs/SPEC.md § 7, audit H-b2).
 */
@Injectable({ providedIn: 'root' })
export class ExportApi {
  private readonly http = inject(HttpClient);
  private readonly injector = inject(Injector);

  /**
   * The file, `step_up` when the API wants the proof first, `demo_refused` when the demo hands out no such file, which
   * the interceptor has said already, what it was refused for, or `failed` for anything else.
   */
  async file(
    address: string,
  ): Promise<Blob | 'step_up' | typeof DEMO_REFUSED | ExportRefused | 'failed'> {
    try {
      return await firstValueFrom(this.http.get(address, { responseType: 'blob' }));
    } catch (error) {
      if (isDemoRefused(error)) return DEMO_REFUSED;
      if (await stepUpAsked(error)) return 'step_up';
      const refusals = await formRefusalsIn(this.injector, await withJsonBody(error));
      return refusals.length > 0 ? { refusals } : 'failed';
    }
  }
}

/** A refusal's answer with its body read: a file is asked for as bytes, so its 422 arrives as a Blob of JSON. */
async function withJsonBody(error: unknown): Promise<unknown> {
  if (!(error instanceof HttpErrorResponse) || !(error.error instanceof Blob)) return error;
  try {
    const body: unknown = JSON.parse(await error.error.text());
    return new HttpErrorResponse({
      error: body,
      headers: error.headers,
      status: error.status,
      statusText: error.statusText,
      url: error.url ?? undefined,
    });
  } catch {
    // A body that is not JSON names no refusal: the answer is a failure, said as one.
    return error;
  }
}

/** Whether a file's failure is the API waiting for who is at the screen to prove it again. */
export async function stepUpAsked(error: unknown): Promise<boolean> {
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
