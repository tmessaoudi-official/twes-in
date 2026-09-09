// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type { MemberMemberRead, WorkingCompanyWorkingCompanyRead } from '../api/types.gen';
import type { CompanyError, CompanyOption, MemberRole, MemberRow } from './company-types';

/** Thrown when the API refuses; carries the code the UI translates. */
export class CompanyRefused extends Error {
  constructor(readonly code: CompanyError) {
    super(code);
  }
}

/** The HTTP edge of the company feature: the only code here that knows endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class CompanyApi {
  private readonly http = inject(HttpClient);

  /** Every company the session's user belongs to. */
  async companies(): Promise<CompanyOption[]> {
    return this.guard(async () => {
      const rows = await firstValueFrom(
        this.http.get<WorkingCompanyWorkingCompanyRead[]>('/api/me/companies'),
      );
      return rows.map(toOption);
    });
  }

  /** Moves the session to another company; the API refuses one the user is not a member of. */
  async switchTo(companyId: string): Promise<CompanyOption> {
    return this.guard(async () =>
      toOption(
        await firstValueFrom(
          this.http.post<WorkingCompanyWorkingCompanyRead>('/api/me/company', { companyId }),
        ),
      ),
    );
  }

  async members(companyId: string): Promise<MemberRow[]> {
    return this.guard(async () => {
      const rows = await firstValueFrom(
        this.http.get<MemberMemberRead[]>(
          `/api/companies/${encodeURIComponent(companyId)}/members`,
        ),
      );
      return rows.map(toRow);
    });
  }

  async addMember(companyId: string, email: string, role: MemberRole): Promise<MemberRow> {
    return this.guard(async () =>
      toRow(
        await firstValueFrom(
          this.http.post<MemberMemberRead>(
            `/api/companies/${encodeURIComponent(companyId)}/members`,
            {
              email,
              role,
            },
          ),
        ),
      ),
    );
  }

  async removeMember(companyId: string, userId: string): Promise<boolean> {
    return this.guard(async () => {
      await firstValueFrom(
        this.http.delete(
          `/api/companies/${encodeURIComponent(companyId)}/members/${encodeURIComponent(userId)}`,
        ),
      );

      return true;
    });
  }

  private async guard<T>(call: () => Promise<T>): Promise<T> {
    try {
      return await call();
    } catch (error) {
      throw new CompanyRefused(codeOf(error));
    }
  }
}

function toOption(row: WorkingCompanyWorkingCompanyRead): CompanyOption {
  return {
    id: row.companyId ?? '',
    name: row.name ?? '',
    status: row.status ?? '',
    role: row.role ?? '',
  };
}

function toRow(row: MemberMemberRead): MemberRow {
  return {
    userId: row.userId ?? '',
    email: row.email ?? '',
    displayName: row.displayName ?? '',
    role: row.role ?? '',
    joinedAt: row.joinedAt ?? '',
    status: row.status ?? 'joined',
  };
}

function codeOf(error: unknown): CompanyError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return 'network';
  }
  switch (error.status) {
    case 404:
      return 'not_found';
    case 409:
      // The API distinguishes these by message; the UI only needs to know both are refusals of an existing row.
      return typeof error.error?.detail === 'string' && error.error.detail.includes('owner')
        ? 'last_owner'
        : 'already_member';
    case 422:
      return 'unknown_user';
    default:
      return 'invalid';
  }
}
