// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';

/** Whether the company is open to support, and the windows an owner may open it for. */
export interface SupportAccessState {
  /** ISO moments, null while support may not read the company. */
  readonly startsAt: string | null;
  readonly endsAt: string | null;
  /** The windows offered, in hours. */
  readonly hours: readonly number[];
}

/** Why the API refused, as the page translates it. */
export type SupportAccessError =
  | 'network'
  | 'not_found'
  | 'owner_only'
  | 'support_window'
  | 'step_up_required'
  /** Something a demo account may not do, which the interceptor has said in words already. */
  | 'demo_refused';

export class SupportAccessRefused extends Error {
  constructor(readonly code: SupportAccessError) {
    super(code);
  }
}

/**
 * The HTTP edge of « Accès du support ». Its endpoints are a plain controller, outside the OpenAPI document, so the
 * shapes are declared here.
 */
@Injectable({ providedIn: 'root' })
export class SupportAccessApi {
  private readonly http = inject(HttpClient);

  async read(companyId: string): Promise<SupportAccessState> {
    return this.call(() => this.http.get<SupportAccessState>(path(companyId)));
  }

  /** Opens the company to support from now for that many hours; a window still open is replaced. */
  async grant(companyId: string, hours: number): Promise<SupportAccessState> {
    return this.call(() => this.http.post<SupportAccessState>(path(companyId), { hours }));
  }

  async end(companyId: string): Promise<void> {
    await this.call(() => this.http.delete(path(companyId)));
  }

  private async call<T>(request: () => ReturnType<HttpClient['get']>): Promise<T> {
    try {
      return (await firstValueFrom(request())) as T;
    } catch (error) {
      throw refusal(error);
    }
  }
}

const path = (companyId: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}/support-access`;

function refusal(error: unknown): SupportAccessRefused {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return new SupportAccessRefused('network');
  }
  if (error.status === 404) return new SupportAccessRefused('not_found');
  const body: unknown = error.error;
  const code =
    typeof body === 'object' && body !== null ? (body as Record<string, unknown>)['error'] : null;
  return new SupportAccessRefused(
    code === 'owner_only' ||
      code === 'support_window' ||
      code === 'step_up_required' ||
      code === 'demo_refused'
      ? code
      : 'network',
  );
}
