// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  ApiCompaniesCompanyIddeliveryNotesGetCollectionResponse,
  DeliveryNoteDeliveryNoteDeliver,
  DeliveryNoteDeliveryNoteRead,
  DeliveryNoteDeliveryNoteWrite,
  DeliveryNoteJsonldDeliveryNoteRead,
  DeliveryNoteOptionsDeliveryNoteOptionsRead,
  InvoiceFromDeliveryNotesInvoiceFromDeliveryNotesWrite,
  InvoiceFromDeliveryNotesInvoiceResourceInvoiceRead,
} from '../api/types.gen';
import type { ListPage } from '../shared/list/list-types';
import {
  DELIVERY_NOTE_STATUSES,
  type DeliveryNoteInput,
  type DeliveryNoteOptions,
  type DeliveryNoteRow,
  type DeliveryNoteSearch,
  type DeliveryNotesError,
  type LineTaxOption,
} from './delivery-notes-types';

/** Thrown when the API refuses; carries the code the UI translates. */
export class DeliveryNotesRefused extends Error {
  constructor(readonly code: DeliveryNotesError) {
    super(code);
  }
}

const LINE_TAX_FAMILIES: readonly LineTaxOption['family'][] = ['vat', 'levy'];

/** The HTTP edge of the delivery notes feature: the only code here that knows endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class DeliveryNotesApi {
  private readonly http = inject(HttpClient);

  /** What the note form offers: the currency, and the active establishments, customers, products, units and line taxes. */
  async options(companyId: string): Promise<DeliveryNoteOptions> {
    return this.guard(async () =>
      toOptions(
        await firstValueFrom(
          this.http.get<DeliveryNoteOptionsDeliveryNoteOptionsRead>(
            `${companyPath(companyId)}/delivery-note-options`,
          ),
        ),
      ),
    );
  }

  /** One page of the company's delivery notes, searched, narrowed and sorted by the API. */
  async notes(companyId: string, search: DeliveryNoteSearch): Promise<ListPage<DeliveryNoteRow>> {
    return this.guard(async () => {
      const page = await firstValueFrom(
        this.http.get<ApiCompaniesCompanyIddeliveryNotesGetCollectionResponse>(
          notePath(companyId),
          {
            headers: { Accept: 'application/ld+json' },
            params: toSearchParams(search),
          },
        ),
      );
      if (page.totalItems === undefined)
        throw new Error('A page of delivery notes came without its total.');
      return { rows: page.member.map(toNote), total: page.totalItems };
    });
  }

  async note(companyId: string, id: string): Promise<DeliveryNoteRow> {
    return this.guard(async () =>
      toNote(
        await firstValueFrom(this.http.get<DeliveryNoteDeliveryNoteRead>(notePath(companyId, id))),
      ),
    );
  }

  /** 422 naming what the API refused. */
  async create(companyId: string, input: DeliveryNoteInput): Promise<DeliveryNoteRow> {
    return this.guard(async () =>
      toNote(
        await firstValueFrom(
          this.http.post<DeliveryNoteDeliveryNoteRead>(notePath(companyId), toBody(input)),
        ),
      ),
    );
  }

  /** 409 once the note is no longer a draft. */
  async revise(companyId: string, id: string, input: DeliveryNoteInput): Promise<DeliveryNoteRow> {
    return this.guard(async () =>
      toNote(
        await firstValueFrom(
          this.http.put<DeliveryNoteDeliveryNoteRead>(notePath(companyId, id), toBody(input)),
        ),
      ),
    );
  }

  /** Numbers a draft; 409 when it is not one or its establishment cannot number it, 422 without lines. */
  async validate(companyId: string, id: string): Promise<DeliveryNoteRow> {
    return this.step(companyId, id, 'validate', null);
  }

  /** Marks a validated note delivered on a day, or on the company's today when the day is left out. */
  async deliver(
    companyId: string,
    id: string,
    deliveredOn: string | null,
  ): Promise<DeliveryNoteRow> {
    const body: DeliveryNoteDeliveryNoteDeliver = { deliveredOn };
    return this.step(companyId, id, 'deliver', body);
  }

  async cancel(companyId: string, id: string): Promise<DeliveryNoteRow> {
    return this.step(companyId, id, 'cancel', null);
  }

  /**
   * Drafts an invoice from validated or delivered notes of one customer and establishment, and answers its id; 409
   * when a note is not validated or delivered or is already on an invoice, 422 when the notes cannot share one.
   */
  async invoice(companyId: string, deliveryNoteIds: readonly string[]): Promise<string> {
    const body: InvoiceFromDeliveryNotesInvoiceFromDeliveryNotesWrite = {
      deliveryNoteIds: [...deliveryNoteIds],
    };
    return this.guard(async () => {
      const draft = await firstValueFrom(
        this.http.post<InvoiceFromDeliveryNotesInvoiceResourceInvoiceRead>(
          `${companyPath(companyId)}/invoices/from-delivery-notes`,
          body,
        ),
      );
      return draft.id ?? '';
    });
  }

  /** Where a note's PDF is downloaded from, with the session the browser already has. */
  pdfUrl(companyId: string, id: string): string {
    return `${notePath(companyId, id)}/pdf`;
  }

  private async step(
    companyId: string,
    id: string,
    action: 'validate' | 'deliver' | 'cancel',
    body: DeliveryNoteDeliveryNoteDeliver | null,
  ): Promise<DeliveryNoteRow> {
    return this.guard(async () =>
      toNote(
        await firstValueFrom(
          this.http.post<DeliveryNoteDeliveryNoteRead>(
            `${notePath(companyId, id)}/${action}`,
            body,
          ),
        ),
      ),
    );
  }

  private async guard<T>(call: () => Promise<T>): Promise<T> {
    try {
      return await call();
    } catch (error) {
      throw new DeliveryNotesRefused(codeOf(error));
    }
  }
}

function codeOf(error: unknown): DeliveryNotesError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return 'network';
  }
  switch (error.status) {
    case 404:
      return 'not_found';
    case 409:
      return 'conflict';
    default:
      return 'invalid';
  }
}

const companyPath = (companyId: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}`;

const notePath = (companyId: string, id?: string): string =>
  `${companyPath(companyId)}/delivery-notes${id === undefined ? '' : `/${encodeURIComponent(id)}`}`;

/** Only what the search asks for: an absent parameter is the API's own default, never an empty one. */
function toSearchParams(search: DeliveryNoteSearch): HttpParams {
  let params = new HttpParams().set('page', search.page).set('itemsPerPage', search.itemsPerPage);
  if (search.q.trim() !== '') params = params.set('q', search.q.trim());
  if (search.status !== null) params = params.set('status', search.status);
  if (search.customerId !== null) params = params.set('customerId', search.customerId);
  if (search.order !== null)
    params = params.set(`order[${search.order.key}]`, search.order.direction);
  return params;
}

function toNote(
  raw: DeliveryNoteDeliveryNoteRead | DeliveryNoteJsonldDeliveryNoteRead,
): DeliveryNoteRow {
  return {
    id: raw.id ?? '',
    number: raw.number ?? null,
    status: DELIVERY_NOTE_STATUSES.find((status) => status === raw.status) ?? 'draft',
    customerId: raw.customerId ?? '',
    establishmentId: raw.establishmentId ?? null,
    customerName: raw.customerSnapshot?.name ?? null,
    issueDate: raw.issueDate ?? null,
    deliveryDate: raw.deliveryDate ?? null,
    deliveryAddress: {
      line1: raw.deliveryAddressLine1 ?? null,
      line2: raw.deliveryAddressLine2 ?? null,
      postalCode: raw.deliveryPostalCode ?? null,
      city: raw.deliveryCity ?? null,
      countryCode: raw.deliveryCountryCode ?? null,
    },
    customerReference: raw.customerReference ?? null,
    remarksPrinted: raw.remarksPrinted ?? null,
    notesInternal: raw.notesInternal ?? null,
    lines: (raw.lines ?? []).map((line) => ({
      productId: line.productId ?? null,
      description: line.description ?? '',
      quantity: line.quantity,
      unitId: line.unitId ?? '',
      unitPriceNet: line.unitPriceNet ?? '',
      taxComponentIds: (line.taxComponentIds ?? []).filter(
        (id): id is string => typeof id === 'string',
      ),
      net: line.net ?? '',
    })),
    subtotalNet: raw.subtotalNet ?? '0',
    taxes: (raw.taxes ?? []).map((tax) => ({
      code: tax.code,
      rate: tax.rate,
      base: tax.base,
      amount: tax.amount,
    })),
    totalTax: raw.totalTax ?? '0',
    total: raw.total ?? '0',
  };
}

function toBody(input: DeliveryNoteInput): DeliveryNoteDeliveryNoteWrite {
  const { deliveryAddress, lines, ...rest } = input;
  return {
    ...rest,
    deliveryAddressLine1: deliveryAddress.line1,
    deliveryAddressLine2: deliveryAddress.line2,
    deliveryPostalCode: deliveryAddress.postalCode,
    deliveryCity: deliveryAddress.city,
    deliveryCountryCode: deliveryAddress.countryCode,
    lines: lines.map((line) => ({ ...line, taxComponentIds: [...line.taxComponentIds] })),
  };
}

function toOptions(raw: DeliveryNoteOptionsDeliveryNoteOptionsRead): DeliveryNoteOptions {
  return {
    currency: raw.currency ?? '',
    currencyScale: raw.currencyScale ?? 2,
    establishments: (raw.establishments ?? []).map((establishment) => ({
      id: establishment.id,
      code: establishment.code,
      name: establishment.name,
      isDefault: establishment.isDefault,
    })),
    customers: (raw.customers ?? []).map((customer) => ({
      id: customer.id,
      number: customer.number,
      name: customer.name,
      excludedFamilies: [...customer.excludedFamilies],
    })),
    products: (raw.products ?? []).map((product) => ({
      id: product.id,
      reference: product.reference,
      name: product.name,
      unitId: product.unitId,
      unitPriceNet: product.unitPriceNet,
      defaultTaxComponentIds: [...product.defaultTaxComponentIds],
    })),
    units: (raw.units ?? []).map((unit) => ({
      id: unit.id,
      code: unit.code,
      name: unit.name,
      decimals: unit.decimals,
    })),
    taxes: (raw.taxes ?? []).flatMap((tax) => {
      const family = LINE_TAX_FAMILIES.find((known) => known === tax.family);
      return family === undefined
        ? []
        : [
            {
              id: tax.id,
              code: tax.code,
              name: tax.name,
              family,
              rate: tax.rate,
              entersVatBase: tax.entersVatBase,
            },
          ];
    }),
  };
}
