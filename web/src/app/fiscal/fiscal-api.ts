// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  CustomerTaxRegimeCustomerTaxRegimeRead,
  TaxComponentTaxComponentRead,
  UnitUnitRead,
} from '../api/types.gen';
import {
  TAX_FAMILIES,
  type CustomerTaxRegimeRow,
  type FiscalError,
  type TaxComponentInput,
  type TaxComponentRow,
  type TaxFamily,
  type UnitInput,
  type UnitRow,
} from './fiscal-types';

/** Thrown when the API refuses; carries the code the UI translates. */
export class FiscalRefused extends Error {
  constructor(readonly code: FiscalError) {
    super(code);
  }
}

/** The HTTP edge of the fiscal feature: the only code here that knows endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class FiscalApi {
  private readonly http = inject(HttpClient);

  async taxComponents(companyId: string): Promise<TaxComponentRow[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<TaxComponentTaxComponentRead[]>(this.path(companyId, 'tax-components')),
        )
      ).map(toTax),
    );
  }

  async createTaxComponent(companyId: string, input: TaxComponentInput): Promise<TaxComponentRow> {
    return this.guard(async () =>
      toTax(
        await firstValueFrom(
          this.http.post<TaxComponentTaxComponentRead>(
            this.path(companyId, 'tax-components'),
            input,
          ),
        ),
      ),
    );
  }

  /** The code and the family are fixed once a tax exists; the API ignores them in a revision. */
  async reviseTaxComponent(
    companyId: string,
    id: string,
    input: TaxComponentInput,
  ): Promise<TaxComponentRow> {
    const changes = {
      name: input.name,
      rate: input.rate,
      amount: input.amount,
      threshold: input.threshold,
      entersVatBase: input.entersVatBase,
      isDefault: input.isDefault,
      isActive: input.isActive,
      exemptionMention: input.exemptionMention,
      sortOrder: input.sortOrder,
    };
    return this.guard(async () =>
      toTax(
        await firstValueFrom(
          this.http.put<TaxComponentTaxComponentRead>(
            `${this.path(companyId, 'tax-components')}/${encodeURIComponent(id)}`,
            changes,
          ),
        ),
      ),
    );
  }

  async units(companyId: string): Promise<UnitRow[]> {
    return this.guard(async () =>
      (await firstValueFrom(this.http.get<UnitUnitRead[]>(this.path(companyId, 'units')))).map(
        toUnit,
      ),
    );
  }

  async createUnit(companyId: string, input: UnitInput): Promise<UnitRow> {
    // A new unit is always active; the API's creation shape has no such field.
    const draft = {
      code: input.code,
      name: input.name,
      decimals: input.decimals,
      sortOrder: input.sortOrder,
    };
    return this.guard(async () =>
      toUnit(
        await firstValueFrom(this.http.post<UnitUnitRead>(this.path(companyId, 'units'), draft)),
      ),
    );
  }

  async reviseUnit(companyId: string, id: string, input: UnitInput): Promise<UnitRow> {
    const changes = {
      name: input.name,
      decimals: input.decimals,
      isActive: input.isActive,
      sortOrder: input.sortOrder,
    };
    return this.guard(async () =>
      toUnit(
        await firstValueFrom(
          this.http.put<UnitUnitRead>(
            `${this.path(companyId, 'units')}/${encodeURIComponent(id)}`,
            changes,
          ),
        ),
      ),
    );
  }

  async customerTaxRegimes(companyId: string): Promise<CustomerTaxRegimeRow[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<CustomerTaxRegimeCustomerTaxRegimeRead[]>(
            this.path(companyId, 'customer-tax-regimes'),
          ),
        )
      ).map(toRegime),
    );
  }

  private path(companyId: string, collection: string): string {
    return `/api/companies/${encodeURIComponent(companyId)}/${collection}`;
  }

  private async guard<T>(call: () => Promise<T>): Promise<T> {
    try {
      return await call();
    } catch (error) {
      throw new FiscalRefused(codeOf(error));
    }
  }
}

const family = (value: string | undefined): TaxFamily =>
  TAX_FAMILIES.find((known) => known === value) ?? 'vat';

function toTax(row: TaxComponentTaxComponentRead): TaxComponentRow {
  return {
    id: row.id ?? '',
    code: row.code ?? '',
    name: row.name ?? '',
    kind: row.kind ?? '',
    family: family(row.family),
    rate: row.rate ?? null,
    amount: row.amount ?? null,
    threshold: row.threshold ?? null,
    entersVatBase: row.entersVatBase ?? false,
    isDefault: row.isDefault ?? false,
    isActive: row.isActive ?? true,
    exemptionMention: row.exemptionMention ?? null,
    sortOrder: row.sortOrder ?? 0,
  };
}

function toUnit(row: UnitUnitRead): UnitRow {
  return {
    id: row.id ?? '',
    code: row.code ?? '',
    name: row.name ?? '',
    decimals: row.decimals ?? 0,
    isActive: row.isActive ?? true,
    sortOrder: row.sortOrder ?? 0,
  };
}

function toRegime(row: CustomerTaxRegimeCustomerTaxRegimeRead): CustomerTaxRegimeRow {
  return {
    code: row.code ?? '',
    label: row.label ?? '',
    excludedFamilies: (row.excludedFamilies ?? []).map(family),
    hasMention: row.hasMention ?? false,
    sortOrder: row.sortOrder ?? 0,
  };
}

function codeOf(error: unknown): FiscalError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return 'network';
  }
  switch (error.status) {
    case 404:
      return 'not_found';
    case 409:
      return 'code_taken';
    default:
      return 'invalid';
  }
}
