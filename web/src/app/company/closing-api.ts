// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  CompanyClosingCompanyClosingRead,
  CompanyClosingCompanyClosingWriteValidationCompanyClosingWrite as CompanyClosingWrite,
} from '../api/types.gen';

/** The company's closed period: the last day no document is dated on or before, and whether the caller may close more. */
export interface CompanyClosing {
  /** YYYY-MM-DD; null while nothing is closed. */
  closedThrough: string | null;
  writable: boolean;
}

/** Why the API refused, as the page translates it: `invalid` is a day not before today or before the one closed. */
export type ClosingError = 'network' | 'not_found' | 'invalid';

/** Thrown when the API refuses; carries the code the page translates. */
export class ClosingRefused extends Error {
  constructor(readonly code: ClosingError) {
    super(code);
  }
}

/** The HTTP edge of the closed period: the only code here that knows its endpoint and generated types. */
@Injectable({ providedIn: 'root' })
export class ClosingApi {
  private readonly http = inject(HttpClient);

  async read(companyId: string): Promise<CompanyClosing> {
    try {
      return toClosing(
        await firstValueFrom(this.http.get<CompanyClosingCompanyClosingRead>(path(companyId))),
      );
    } catch (error) {
      throw new ClosingRefused(codeOf(error));
    }
  }

  /** Closes the books through a day before today; a closed period never opens again. */
  async closeThrough(companyId: string, day: string): Promise<CompanyClosing> {
    const body: CompanyClosingWrite = { closedThrough: day };
    try {
      return toClosing(
        await firstValueFrom(
          this.http.put<CompanyClosingCompanyClosingRead>(path(companyId), body),
        ),
      );
    } catch (error) {
      throw new ClosingRefused(codeOf(error));
    }
  }
}

const path = (companyId: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}/closing`;

function toClosing(raw: CompanyClosingCompanyClosingRead): CompanyClosing {
  return { closedThrough: raw.closedThrough ?? null, writable: raw.writable === true };
}

function codeOf(error: unknown): ClosingError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return 'network';
  }
  return error.status === 404 ? 'not_found' : 'invalid';
}
