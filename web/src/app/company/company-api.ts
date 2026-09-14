// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  CompanyProfileCompanyProfileRead,
  CompanyProfileCompanyProfileWrite,
  EstablishmentEstablishmentRead,
  EstablishmentEstablishmentWrite,
  MemberMemberRead,
  NumberingSeriesNumberingSeriesRead,
  NumberingSeriesNumberingSeriesWrite,
  WorkingCompanyWorkingCompanyRead,
} from '../api/types.gen';
import {
  RESET_PERIODS,
  type CompanyError,
  type CompanyOption,
  type CompanyProfile,
  type CompanyProfileChanges,
  type EstablishmentInput,
  type EstablishmentRow,
  type MemberRole,
  type MemberRow,
  type NumberingChanges,
  type NumberingSeriesRow,
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

  /** The company's establishments, the default first, each carrying the shape of a code. */
  async establishments(companyId: string): Promise<EstablishmentRow[]> {
    return this.guard(
      async () =>
        (
          await firstValueFrom(
            this.http.get<EstablishmentEstablishmentRead[]>(
              companyPath(companyId, 'establishments'),
            ),
          )
        ).map(toEstablishment),
      rowCodeOf,
    );
  }

  /** A new establishment numbers its documents the way the default one does; 409 when its code is taken. */
  async createEstablishment(
    companyId: string,
    input: EstablishmentInput,
  ): Promise<EstablishmentRow> {
    const body: EstablishmentEstablishmentWrite = { ...input };
    return this.guard(
      async () =>
        toEstablishment(
          await firstValueFrom(
            this.http.post<EstablishmentEstablishmentRead>(
              companyPath(companyId, 'establishments'),
              body,
            ),
          ),
        ),
      rowCodeOf,
    );
  }

  async reviseEstablishment(
    companyId: string,
    id: string,
    input: EstablishmentInput,
  ): Promise<EstablishmentRow> {
    const body: EstablishmentEstablishmentWrite = { ...input };
    return this.guard(
      async () =>
        toEstablishment(
          await firstValueFrom(
            this.http.put<EstablishmentEstablishmentRead>(
              `${companyPath(companyId, 'establishments')}/${encodeURIComponent(id)}`,
              body,
            ),
          ),
        ),
      rowCodeOf,
    );
  }

  async numberingSeries(companyId: string): Promise<NumberingSeriesRow[]> {
    return this.guard(
      async () =>
        (
          await firstValueFrom(
            this.http.get<NumberingSeriesNumberingSeriesRead[]>(
              companyPath(companyId, 'numbering-series'),
            ),
          )
        ).map(toSeries),
      rowCodeOf,
    );
  }

  async reviseNumberingSeries(
    companyId: string,
    id: string,
    changes: NumberingChanges,
  ): Promise<NumberingSeriesRow> {
    const body: NumberingSeriesNumberingSeriesWrite = { ...changes };
    return this.guard(
      async () =>
        toSeries(
          await firstValueFrom(
            this.http.put<NumberingSeriesNumberingSeriesRead>(
              `${companyPath(companyId, 'numbering-series')}/${encodeURIComponent(id)}`,
              body,
            ),
          ),
        ),
      rowCodeOf,
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

const companyPath = (companyId: string, collection: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}/${collection}`;

/** A row refused for what it says (422), for a code another row already has (409), or missing (404). */
function rowCodeOf(error: unknown): CompanyError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return 'network';
  }
  if (error.status === 404) {
    return 'not_found';
  }
  return error.status === 409 ? 'code_taken' : 'invalid';
}

function toEstablishment(row: EstablishmentEstablishmentRead): EstablishmentRow {
  return {
    id: row.id ?? '',
    code: row.code ?? '',
    name: row.name ?? '',
    addressLine1: row.addressLine1 ?? null,
    addressLine2: row.addressLine2 ?? null,
    postalCode: row.postalCode ?? null,
    city: row.city ?? null,
    phone: row.phone ?? null,
    email: row.email ?? null,
    isDefault: row.isDefault ?? false,
    codePattern: row.codePattern ?? '',
    codeLocked: row.codeLocked ?? false,
  };
}

function toSeries(row: NumberingSeriesNumberingSeriesRead): NumberingSeriesRow {
  return {
    id: row.id ?? '',
    establishmentId: row.establishmentId ?? '',
    establishmentCode: row.establishmentCode ?? '',
    documentType: row.documentType ?? '',
    format: row.format ?? '',
    nextNumber: row.nextNumber ?? 1,
    resetPeriod: RESET_PERIODS.find((period) => period === row.resetPeriod) ?? 'yearly',
    isDefault: row.isDefault ?? false,
    numbered: row.numbered ?? false,
    preview: row.preview ?? '',
  };
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
