// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  CompanySecurityCompanySecurityRead,
  CompanySecurityCompanySecurityWrite,
} from '../api/types.gen';

/** Whether the company requires a second factor of its members, and whether the caller may change that. */
export interface CompanySecurity {
  mfaRequired: boolean;
  writable: boolean;
}

/** Why the API refused, as the page translates it. */
export type CompanySecurityError = 'network' | 'not_found' | 'invalid';

/** Thrown when the API refuses; carries the code the page translates. */
export class CompanySecurityRefused extends Error {
  constructor(readonly code: CompanySecurityError) {
    super(code);
  }
}

/** The HTTP edge of a company's sign-in requirements: the only code here that knows the endpoint and its types. */
@Injectable({ providedIn: 'root' })
export class CompanySecurityApi {
  private readonly http = inject(HttpClient);

  async read(companyId: string): Promise<CompanySecurity> {
    try {
      return toSecurity(
        await firstValueFrom(this.http.get<CompanySecurityCompanySecurityRead>(path(companyId))),
      );
    } catch (error) {
      throw new CompanySecurityRefused(codeOf(error));
    }
  }

  async requireSecondFactor(companyId: string, required: boolean): Promise<CompanySecurity> {
    const body: CompanySecurityCompanySecurityWrite = { mfaRequired: required };
    try {
      return toSecurity(
        await firstValueFrom(
          this.http.put<CompanySecurityCompanySecurityRead>(path(companyId), body),
        ),
      );
    } catch (error) {
      throw new CompanySecurityRefused(codeOf(error));
    }
  }
}

const path = (companyId: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}/security`;

function toSecurity(raw: CompanySecurityCompanySecurityRead): CompanySecurity {
  return { mfaRequired: raw.mfaRequired === true, writable: raw.writable === true };
}

function codeOf(error: unknown): CompanySecurityError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return 'network';
  }
  return error.status === 404 ? 'not_found' : 'invalid';
}
