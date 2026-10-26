// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type { CompanyClosing } from '../auth/auth-types';
import { refusedFor } from '../shared/errors/said-already';

export type CompanyClosingError =
  'step_up_required' | 'owner_only' | 'reopen_period_over' | 'not_closed_down' | 'refused';

const KNOWN: readonly CompanyClosingError[] = [
  'step_up_required',
  'owner_only',
  'reopen_period_over',
  'not_closed_down',
];

/** Why the company was not closed or reopened, as the API's code says it. */
export class CompanyClosingRefused extends Error {
  constructor(readonly code: CompanyClosingError) {
    super(code);
  }
}

/** The HTTP edge of « Fermer l'entreprise » and « Rouvrir l'entreprise »: the only code that knows the endpoints. */
@Injectable({ providedIn: 'root' })
export class CompanyClosingApi {
  private readonly http = inject(HttpClient);

  /** Closes the company now: the last day it is kept, and until when its owner may reopen it. */
  async closeDown(companyId: string): Promise<CompanyClosing> {
    try {
      return await firstValueFrom(
        this.http.post<CompanyClosing>(`/api/companies/${companyId}/close-down`, {}),
      );
    } catch (error) {
      throw refusalOf(error);
    }
  }

  async reopen(companyId: string): Promise<void> {
    try {
      await firstValueFrom(this.http.post<void>(`/api/companies/${companyId}/reopen`, {}));
    } catch (error) {
      throw refusalOf(error);
    }
  }
}

/** Whether a refusal asks who is at the screen to prove it again. */
export const askedForProof = (error: unknown): boolean =>
  error instanceof CompanyClosingRefused && error.code === 'step_up_required';

function refusalOf(error: unknown): CompanyClosingRefused {
  const body: unknown = error instanceof HttpErrorResponse ? error.error : null;
  const code =
    typeof body === 'object' && body !== null && 'error' in body ? String(body.error) : '';
  return refusedFor(
    error,
    new CompanyClosingRefused(KNOWN.find((candidate) => candidate === code) ?? 'refused'),
  );
}
