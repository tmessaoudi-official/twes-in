// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  ContactContactRead,
  ContactContactWrite,
  CustomerCustomerRead,
  CustomerCustomerWrite,
  CustomerGroupCustomerGroupRead,
  CustomerGroupCustomerGroupWrite,
  CustomerOptionsCustomerOptionsRead,
} from '../api/types.gen';
import {
  CUSTOMER_KINDS,
  type ContactInput,
  type ContactRow,
  type CustomerAddress,
  type CustomerGroupInput,
  type CustomerGroupRow,
  type CustomerInput,
  type CustomerOptions,
  type CustomerRow,
  type CustomersError,
  type TaxFamily,
} from './customers-types';

/** Thrown when the API refuses; carries the code the UI translates. */
export class CustomersRefused extends Error {
  constructor(readonly code: CustomersError) {
    super(code);
  }
}

const TAX_FAMILIES: readonly TaxFamily[] = ['vat', 'levy', 'stamp', 'withholding'];

/** The HTTP edge of the customers feature: the only code here that knows endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class CustomersApi {
  private readonly http = inject(HttpClient);

  /** What the customer form offers: the preset's registration numbers, its regimes and the company's active taxes. */
  async options(companyId: string): Promise<CustomerOptions> {
    return this.guard(async () =>
      toOptions(
        await firstValueFrom(
          this.http.get<CustomerOptionsCustomerOptionsRead>(path(companyId, 'customer-options')),
        ),
      ),
    );
  }

  async customers(companyId: string): Promise<CustomerRow[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(this.http.get<CustomerCustomerRead[]>(path(companyId, 'customers')))
      ).map(toCustomer),
    );
  }

  async customer(companyId: string, id: string): Promise<CustomerRow> {
    return this.guard(async () =>
      toCustomer(
        await firstValueFrom(this.http.get<CustomerCustomerRead>(path(companyId, 'customers', id))),
      ),
    );
  }

  /** 409 when another customer of the company has the number; 422 naming the field the API refused. */
  async createCustomer(companyId: string, input: CustomerInput): Promise<CustomerRow> {
    return this.guard(async () =>
      toCustomer(
        await firstValueFrom(
          this.http.post<CustomerCustomerRead>(path(companyId, 'customers'), toCustomerBody(input)),
        ),
      ),
    );
  }

  async reviseCustomer(companyId: string, id: string, input: CustomerInput): Promise<CustomerRow> {
    return this.guard(async () =>
      toCustomer(
        await firstValueFrom(
          this.http.put<CustomerCustomerRead>(
            path(companyId, 'customers', id),
            toCustomerBody(input),
          ),
        ),
      ),
    );
  }

  async groups(companyId: string): Promise<CustomerGroupRow[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<CustomerGroupCustomerGroupRead[]>(path(companyId, 'customer-groups')),
        )
      ).map(toGroup),
    );
  }

  async createGroup(companyId: string, input: CustomerGroupInput): Promise<CustomerGroupRow> {
    const body: CustomerGroupCustomerGroupWrite = { ...input };
    return this.guard(
      async () =>
        toGroup(
          await firstValueFrom(
            this.http.post<CustomerGroupCustomerGroupRead>(
              path(companyId, 'customer-groups'),
              body,
            ),
          ),
        ),
      'name_taken',
    );
  }

  async reviseGroup(
    companyId: string,
    id: string,
    input: CustomerGroupInput,
  ): Promise<CustomerGroupRow> {
    const body: CustomerGroupCustomerGroupWrite = { ...input };
    return this.guard(
      async () =>
        toGroup(
          await firstValueFrom(
            this.http.put<CustomerGroupCustomerGroupRead>(
              path(companyId, 'customer-groups', id),
              body,
            ),
          ),
        ),
      'name_taken',
    );
  }

  /** 409 while a customer is still in the group. */
  async deleteGroup(companyId: string, id: string): Promise<void> {
    await this.guard(
      async () => firstValueFrom(this.http.delete(path(companyId, 'customer-groups', id))),
      'in_use',
    );
  }

  /** A customer's contacts, the primary one first. */
  async contacts(companyId: string, customerId: string): Promise<ContactRow[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<ContactContactRead[]>(contactsPath(companyId, customerId)),
        )
      ).map(toContact),
    );
  }

  async addContact(
    companyId: string,
    customerId: string,
    input: ContactInput,
  ): Promise<ContactRow> {
    const body: ContactContactWrite = { ...input };
    return this.guard(async () =>
      toContact(
        await firstValueFrom(
          this.http.post<ContactContactRead>(contactsPath(companyId, customerId), body),
        ),
      ),
    );
  }

  async reviseContact(
    companyId: string,
    customerId: string,
    id: string,
    input: ContactInput,
  ): Promise<ContactRow> {
    const body: ContactContactWrite = { ...input };
    return this.guard(async () =>
      toContact(
        await firstValueFrom(
          this.http.put<ContactContactRead>(
            `${contactsPath(companyId, customerId)}/${encodeURIComponent(id)}`,
            body,
          ),
        ),
      ),
    );
  }

  async removeContact(companyId: string, customerId: string, id: string): Promise<void> {
    await this.guard(async () =>
      firstValueFrom(
        this.http.delete(`${contactsPath(companyId, customerId)}/${encodeURIComponent(id)}`),
      ),
    );
  }

  /** A 409 means what the endpoint makes it mean: a number or name another row has, or a group still in use. */
  private async guard<T>(
    call: () => Promise<T>,
    conflict: CustomersError = 'number_taken',
  ): Promise<T> {
    try {
      return await call();
    } catch (error) {
      throw new CustomersRefused(codeOf(error, conflict));
    }
  }
}

function codeOf(error: unknown, conflict: CustomersError): CustomersError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return 'network';
  }
  switch (error.status) {
    case 404:
      return 'not_found';
    case 409:
      return conflict;
    default:
      return 'invalid';
  }
}

const path = (companyId: string, collection: string, id?: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}/${collection}${id === undefined ? '' : `/${encodeURIComponent(id)}`}`;

const contactsPath = (companyId: string, customerId: string): string =>
  `${path(companyId, 'customers', customerId)}/contacts`;

function toCustomer(raw: CustomerCustomerRead): CustomerRow {
  const shipping: CustomerAddress = {
    line1: raw.shippingAddressLine1 ?? null,
    line2: raw.shippingAddressLine2 ?? null,
    postalCode: raw.shippingPostalCode ?? null,
    city: raw.shippingCity ?? null,
    countryCode: raw.shippingCountryCode ?? null,
  };
  return {
    id: raw.id ?? '',
    number: raw.number ?? '',
    kind: CUSTOMER_KINDS.find((kind) => kind === raw.kind) ?? 'company',
    customerGroupId: raw.customerGroupId ?? null,
    taxRegime: raw.taxRegime ?? '',
    name: raw.name ?? '',
    legalName: raw.legalName ?? null,
    identifiers: raw.identifiers ?? {},
    email: raw.email ?? null,
    phone: raw.phone ?? null,
    website: raw.website ?? null,
    billingAddress: {
      line1: raw.billingAddressLine1 ?? null,
      line2: raw.billingAddressLine2 ?? null,
      postalCode: raw.billingPostalCode ?? null,
      city: raw.billingCity ?? null,
      countryCode: raw.billingCountryCode ?? null,
    },
    shippingAddress: Object.values(shipping).every((part) => part === null) ? null : shipping,
    defaultTaxComponentIds: (raw.defaultTaxComponentIds ?? []).filter(
      (id): id is string => typeof id === 'string',
    ),
    defaultDiscountRate: raw.defaultDiscountRate ?? null,
    notes: raw.notes ?? null,
    isActive: raw.isActive ?? true,
  };
}

function toCustomerBody(input: CustomerInput): CustomerCustomerWrite {
  const { billingAddress, shippingAddress, ...rest } = input;
  return {
    ...rest,
    identifiers: { ...input.identifiers },
    billingAddressLine1: billingAddress.line1,
    billingAddressLine2: billingAddress.line2,
    billingPostalCode: billingAddress.postalCode,
    billingCity: billingAddress.city,
    billingCountryCode: billingAddress.countryCode,
    shippingAddressLine1: shippingAddress?.line1 ?? null,
    shippingAddressLine2: shippingAddress?.line2 ?? null,
    shippingPostalCode: shippingAddress?.postalCode ?? null,
    shippingCity: shippingAddress?.city ?? null,
    shippingCountryCode: shippingAddress?.countryCode ?? null,
  };
}

function toOptions(raw: CustomerOptionsCustomerOptionsRead): CustomerOptions {
  return {
    countryCode: raw.countryCode ?? '',
    identifiers: (raw.identifiers ?? []).map((identifier) => ({
      key: identifier.key,
      label: identifier.label,
      pattern: identifier.pattern,
      requiredForBusiness: identifier.requiredForBusiness,
    })),
    regimes: (raw.regimes ?? []).map((regime) => ({
      code: regime.code,
      label: regime.label,
      excludedFamilies: regime.excludedFamilies.flatMap((family) =>
        TAX_FAMILIES.filter((known) => known === family),
      ),
    })),
    taxes: (raw.taxes ?? []).flatMap((tax) => {
      const family = TAX_FAMILIES.find((known) => known === tax.family);
      return family === undefined ? [] : [{ id: tax.id, code: tax.code, name: tax.name, family }];
    }),
  };
}

function toGroup(raw: CustomerGroupCustomerGroupRead): CustomerGroupRow {
  return {
    id: raw.id ?? '',
    name: raw.name ?? '',
    description: raw.description ?? null,
    customerCount: raw.customerCount ?? 0,
  };
}

function toContact(raw: ContactContactRead): ContactRow {
  return {
    id: raw.id ?? '',
    firstName: raw.firstName ?? null,
    lastName: raw.lastName ?? null,
    email: raw.email ?? null,
    phone: raw.phone ?? null,
    role: raw.role ?? null,
    isPrimary: raw.isPrimary ?? false,
  };
}
