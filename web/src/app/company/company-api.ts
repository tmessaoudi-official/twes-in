// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  CompanyProfileCompanyProfileRead,
  CompanyProfileCompanyProfileWrite,
  MemberMemberRead,
  WorkingCompanyWorkingCompanyRead,
} from '../api/types.gen';
import type {
  CompanyError,
  CompanyOption,
  CompanyProfile,
  CompanyProfileChanges,
  MemberRole,
  MemberRow,
} from './company-types';

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

  /** What the company's documents say about it, with what its fiscal preset asks for. */
  async profile(companyId: string): Promise<CompanyProfile> {
    return this.guard(
      async () =>
        toProfile(
          await firstValueFrom(
            this.http.get<CompanyProfileCompanyProfileRead>(
              `/api/companies/${encodeURIComponent(companyId)}/profile`,
            ),
          ),
        ),
      profileCodeOf,
    );
  }

  /** Answers the profile as the API kept it, normalised; 422 when the preset refuses a value. */
  async reviseProfile(companyId: string, changes: CompanyProfileChanges): Promise<CompanyProfile> {
    const body: CompanyProfileCompanyProfileWrite = {
      ...changes,
      identifiers: { ...changes.identifiers },
    };
    return this.guard(
      async () =>
        toProfile(
          await firstValueFrom(
            this.http.put<CompanyProfileCompanyProfileRead>(
              `/api/companies/${encodeURIComponent(companyId)}/profile`,
              body,
            ),
          ),
        ),
      profileCodeOf,
    );
  }

  private async guard<T>(
    call: () => Promise<T>,
    refusal: (error: unknown) => CompanyError = codeOf,
  ): Promise<T> {
    try {
      return await call();
    } catch (error) {
      throw new CompanyRefused(refusal(error));
    }
  }
}

function toProfile(raw: CompanyProfileCompanyProfileRead): CompanyProfile {
  return {
    name: raw.name ?? '',
    countryCode: raw.countryCode ?? '',
    writable: raw.writable ?? false,
    legalName: raw.legalName ?? null,
    legalForm: raw.legalForm ?? null,
    identifiers: raw.identifiers ?? {},
    addressLine1: raw.addressLine1 ?? null,
    addressLine2: raw.addressLine2 ?? null,
    postalCode: raw.postalCode ?? null,
    city: raw.city ?? null,
    email: raw.email ?? null,
    phone: raw.phone ?? null,
    website: raw.website ?? null,
    iban: raw.iban ?? null,
    bic: raw.bic ?? null,
    vatRegime: raw.vatRegime ?? 'standard',
    invoiceFooterText: raw.invoiceFooterText ?? null,
    latePenaltyText: raw.latePenaltyText ?? null,
    identifierFields: (raw.identifierFields ?? []).map((field) => ({
      key: field.key,
      label: field.label,
      pattern: field.pattern,
      required: field.required,
    })),
    vatRegimes: (raw.vatRegimes ?? []).map((regime) => ({
      code: regime.code,
      label: regime.label,
    })),
  };
}

/** A profile the API refuses is a value its preset does not accept; nothing to do with members. */
function profileCodeOf(error: unknown): CompanyError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return 'network';
  }
  return error.status === 404 ? 'not_found' : 'invalid';
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
