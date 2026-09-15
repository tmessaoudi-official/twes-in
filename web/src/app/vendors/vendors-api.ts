// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  VendorOptionsVendorOptionsRead,
  VendorVendorRead,
  VendorVendorWrite,
} from '../api/types.gen';
import type { VendorInput, VendorOptions, VendorRow, VendorsError } from './vendors-types';

/** Thrown when the API refuses; carries the code the UI translates. */
export class VendorsRefused extends Error {
  constructor(readonly code: VendorsError) {
    super(code);
  }
}

/** The HTTP edge of the vendors feature: the only code here that knows endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class VendorsApi {
  private readonly http = inject(HttpClient);

  /** What the vendor form offers: the company's country and its preset's registration numbers. */
  async options(companyId: string): Promise<VendorOptions> {
    return this.guard(async () =>
      toOptions(
        await firstValueFrom(
          this.http.get<VendorOptionsVendorOptionsRead>(path(companyId, 'vendor-options')),
        ),
      ),
    );
  }

  async vendors(companyId: string): Promise<VendorRow[]> {
    return this.guard(async () =>
      (await firstValueFrom(this.http.get<VendorVendorRead[]>(path(companyId, 'vendors')))).map(
        toVendor,
      ),
    );
  }

  async vendor(companyId: string, id: string): Promise<VendorRow> {
    return this.guard(async () =>
      toVendor(
        await firstValueFrom(this.http.get<VendorVendorRead>(path(companyId, 'vendors', id))),
      ),
    );
  }

  /** 409 when another vendor of the company has the number; 422 naming the field the API refused. */
  async createVendor(companyId: string, input: VendorInput): Promise<VendorRow> {
    return this.guard(async () =>
      toVendor(
        await firstValueFrom(
          this.http.post<VendorVendorRead>(path(companyId, 'vendors'), toVendorBody(input)),
        ),
      ),
    );
  }

  async reviseVendor(companyId: string, id: string, input: VendorInput): Promise<VendorRow> {
    return this.guard(async () =>
      toVendor(
        await firstValueFrom(
          this.http.put<VendorVendorRead>(path(companyId, 'vendors', id), toVendorBody(input)),
        ),
      ),
    );
  }

  private async guard<T>(call: () => Promise<T>): Promise<T> {
    try {
      return await call();
    } catch (error) {
      throw new VendorsRefused(codeOf(error));
    }
  }
}

function codeOf(error: unknown): VendorsError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return 'network';
  }
  switch (error.status) {
    case 404:
      return 'not_found';
    case 409:
      return 'number_taken';
    default:
      return 'invalid';
  }
}

const path = (companyId: string, collection: string, id?: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}/${collection}${id === undefined ? '' : `/${encodeURIComponent(id)}`}`;

function toVendor(raw: VendorVendorRead): VendorRow {
  return {
    id: raw.id ?? '',
    number: raw.number ?? '',
    name: raw.name ?? '',
    legalName: raw.legalName ?? null,
    identifiers: raw.identifiers ?? {},
    email: raw.email ?? null,
    phone: raw.phone ?? null,
    website: raw.website ?? null,
    address: {
      line1: raw.addressLine1 ?? null,
      line2: raw.addressLine2 ?? null,
      postalCode: raw.postalCode ?? null,
      city: raw.city ?? null,
      countryCode: raw.countryCode ?? null,
    },
    iban: raw.iban ?? null,
    bic: raw.bic ?? null,
    paymentTermsDays: raw.paymentTermsDays ?? null,
    notes: raw.notes ?? null,
    isActive: raw.isActive ?? true,
  };
}

function toVendorBody(input: VendorInput): VendorVendorWrite {
  const { address, ...rest } = input;
  return {
    ...rest,
    identifiers: { ...input.identifiers },
    addressLine1: address.line1,
    addressLine2: address.line2,
    postalCode: address.postalCode,
    city: address.city,
    countryCode: address.countryCode,
  };
}

function toOptions(raw: VendorOptionsVendorOptionsRead): VendorOptions {
  return {
    countryCode: raw.countryCode ?? '',
    identifiers: (raw.identifiers ?? []).map((identifier) => ({
      key: identifier.key,
      label: identifier.label,
      pattern: identifier.pattern,
    })),
  };
}
