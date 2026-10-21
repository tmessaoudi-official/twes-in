// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpContext, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  ApiCompaniesCompanyIddeliveryNotesGetCollectionResponse,
  DeliveryNoteDeliveryNoteDeliverValidationDeliveryNoteDeliver as DeliveryNoteDeliveryNoteDeliver,
  DeliveryNoteDeliveryNoteRead,
  DeliveryNoteDocumentPreviewDocumentPreviewValidationDeliveryNoteWrite as DeliveryNoteDocumentPreview,
  DeliveryNoteDeliveryNoteWriteRecordReviseValidationDeliveryNoteWriteRecordRevise as DeliveryNoteDeliveryNoteRevise,
  DeliveryNoteDeliveryNoteWriteValidationDeliveryNoteWrite as DeliveryNoteDeliveryNoteWrite,
  DeliveryNoteJsonldDeliveryNoteRead,
  DeliveryNoteCustomerPickDeliveryNoteCustomerPickRead,
  DeliveryNoteOptionsDeliveryNoteOptionsRead,
  DeliveryNoteProductPickDeliveryNoteProductPickRead,
  DeliveryNoteCreditDeliveryNoteCreditRead,
  DeliveryNoteLeftDeliveryNoteLeftRead,
  DeliveryNoteStatusCountsDeliveryNoteStatusCountsRead,
  InvoiceFromDeliveryNotesInvoiceFromDeliveryNotesWriteValidationInvoiceFromDeliveryNotesWrite as InvoiceFromDeliveryNotesInvoiceFromDeliveryNotesWrite,
  InvoiceFromDeliveryNotesInvoiceResourceInvoiceReadValidationInvoiceFromDeliveryNotesWrite as InvoiceFromDeliveryNotesInvoiceResourceInvoiceRead,
  InvoicingCheckInvoicingCheckReadValidationInvoicingCheckWrite as InvoicingCheckRead,
  InvoicingCheckInvoicingCheckWriteValidationInvoicingCheckWrite as InvoicingCheckWrite,
} from '../api/types.gen';
import type { PreviewBody } from '../shared/documents/document-figures';
import { isRecordChanged } from '../shared/errors/record-changed';
import { SILENT } from '../shared/feedback/activity-interceptor';
import { type ExportFormat, exportAddress } from '../shared/list/export-address';
import { apiRangeKey } from '../shared/list/list-filters';
import type { ListPage } from '../shared/list/list-types';
import { type PickAsked, pickParams } from '../shared/form/pick-api';
import {
  DELIVERY_NOTE_STATUSES,
  type DeliveryNoteInput,
  type DeliveryNoteOptions,
  type DeliveryNoteRow,
  type DeliveryNoteSearch,
  type DeliveryNoteCredit,
  type DeliveryNoteLeft,
  type DeliveryNoteStatusCounts,
  type DeliveryNotesError,
  type CustomerOption,
  type LineTaxOption,
  type ProductOption,
  type InvoiceDraftOption,
  type InvoicingCheck,
} from './delivery-notes-types';
import { InvoicesApi, refusedField } from '../invoices/invoices-api';
import { trackingOf } from '../products/products-types';
import { refusedFor } from '../shared/errors/said-already';

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
  private readonly invoices = inject(InvoicesApi);

  /** What the note form offers: the currency, and the active establishments, units and line taxes. */
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

  /**
   * The few customers or products a person means, or — given ids — exactly the records a note already names, whether
   * or not they are still offered. The catalogue is never read whole.
   */
  async pickCustomers(companyId: string, asked: PickAsked): Promise<CustomerOption[]> {
    return this.guard(async () => {
      const rows = await firstValueFrom(
        this.http.get<DeliveryNoteCustomerPickDeliveryNoteCustomerPickRead[]>(
          `${companyPath(companyId)}/delivery-note-options/customers`,
          { params: pickParams(asked) },
        ),
      );
      return rows.map((customer) => ({
        id: customer.id ?? '',
        number: customer.number,
        name: customer.name,
        excludedFamilies: [...customer.excludedFamilies],
      }));
    });
  }

  async pickProducts(companyId: string, asked: PickAsked): Promise<ProductOption[]> {
    return this.guard(async () => {
      const rows = await firstValueFrom(
        this.http.get<DeliveryNoteProductPickDeliveryNoteProductPickRead[]>(
          `${companyPath(companyId)}/delivery-note-options/products`,
          { params: pickParams(asked) },
        ),
      );
      return rows.map((product) => ({
        id: product.id ?? '',
        reference: product.reference,
        name: product.name,
        unitId: product.unitId,
        unitPriceNet: product.unitPriceNet,
        defaultTaxComponentIds: [...product.defaultTaxComponentIds],
        tracking: trackingOf(product.tracking),
        mainPhotoId: product.mainPhotoId ?? null,
      }));
    });
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

  /** Where the delivery notes the search finds are downloaded as a file, every page of them. */
  exportUrl(companyId: string, search: DeliveryNoteSearch, format: ExportFormat): string {
    return exportAddress(companyId, 'delivery-notes', toSearchParams(search), format);
  }

  /** What of the note is still to invoice, line by line: what the company's invoices already take, drafts included. */
  async left(companyId: string, id: string): Promise<DeliveryNoteLeft> {
    return this.guard(async () => {
      const left = await firstValueFrom(
        this.http.get<DeliveryNoteLeftDeliveryNoteLeftRead>(`${notePath(companyId, id)}/left`),
      );
      if (left.lines === undefined) throw new Error('A note came without what is left of it.');
      return { lines: left.lines.map((line) => ({ ...line })) };
    });
  }

  /** What delivering the note would do to its customer's credit limit. */
  async credit(companyId: string, id: string): Promise<DeliveryNoteCredit> {
    return this.guard(async () => {
      const credit = await firstValueFrom(
        this.http.get<DeliveryNoteCreditDeliveryNoteCreditRead>(
          `${notePath(companyId, id)}/credit`,
        ),
      );
      if (credit.limit === undefined || credit.over === undefined)
        throw new Error('A credit position came without its figures.');
      return {
        limit: credit.limit,
        owed: credit.owed ?? '0',
        noteTotal: credit.noteTotal ?? '0',
        afterDelivery: credit.afterDelivery ?? '0',
        over: credit.over,
      };
    });
  }

  /** What each status chip would list under the list's words and customer. */
  async statusCounts(
    companyId: string,
    search: DeliveryNoteSearch,
  ): Promise<DeliveryNoteStatusCounts> {
    return this.guard(async () => {
      const counts = await firstValueFrom(
        this.http.get<DeliveryNoteStatusCountsDeliveryNoteStatusCountsRead>(
          `${companyPath(companyId)}/delivery-note-status-counts`,
          { params: toCountParams(search) },
        ),
      );
      if (counts.all === undefined || counts.statuses === undefined)
        throw new Error('Status counts came without their figures.');
      return { all: counts.all, statuses: { ...counts.statuses } };
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

  /**
   * 409 once the note is no longer a draft; `version` is the one the form was opened at, and a later one saved
   * elsewhere answers `record_changed`.
   */
  async revise(
    companyId: string,
    id: string,
    input: DeliveryNoteInput,
    version: number | null,
  ): Promise<DeliveryNoteRow> {
    const body: DeliveryNoteDeliveryNoteRevise = { ...toBody(input), version };
    return this.guard(async () =>
      toNote(
        await firstValueFrom(
          this.http.put<DeliveryNoteDeliveryNoteRead>(notePath(companyId, id), body),
        ),
      ),
    );
  }

  /**
   * What the body would come to if it were saved, a new note's or the draft's, kept nowhere: asked as typing rests, so
   * quietly, without the activity bar.
   */
  async preview(
    companyId: string,
    id: string | null,
    input: DeliveryNoteInput,
  ): Promise<PreviewBody> {
    const path =
      id === null ? `${notePath(companyId)}/preview` : `${notePath(companyId, id)}/preview`;
    return this.guard(async () =>
      firstValueFrom(
        this.http.post<DeliveryNoteDocumentPreview>(path, toBody(input), {
          context: new HttpContext().set(SILENT, true),
        }),
      ),
    );
  }

  /** Numbers a draft; 409 when it is not one or its establishment cannot number it, 422 without lines. */
  async validate(companyId: string, id: string): Promise<DeliveryNoteRow> {
    return this.step(companyId, id, 'validate', null);
  }

  /** Numbers a draft and marks it delivered today, both or neither; refused as `validate` refuses. */
  async validateAndDeliver(companyId: string, id: string): Promise<DeliveryNoteRow> {
    return this.step(companyId, id, 'validate-and-deliver', null);
  }

  /**
   * Marks a validated note delivered at a moment written on the company's clock (`YYYY-MM-DDTHH:MM`), or now when
   * it is left out.
   */
  async deliver(companyId: string, id: string, delivered: string | null): Promise<DeliveryNoteRow> {
    const body: DeliveryNoteDeliveryNoteDeliver = { delivered };
    return this.step(companyId, id, 'deliver', body);
  }

  async cancel(companyId: string, id: string): Promise<DeliveryNoteRow> {
    return this.step(companyId, id, 'cancel', null);
  }

  /**
   * Drafts an invoice from validated or delivered notes of one customer and establishment, and answers its id; 409
   * when a note is not validated or delivered or is already on an invoice, 422 when the notes cannot share one.
   */
  /** What invoicing the selected notes would do with each, checked by the API before it runs; nothing changes. */
  async invoicingCheck(
    companyId: string,
    deliveryNoteIds: readonly string[],
    invoiceId: string | null = null,
  ): Promise<InvoicingCheck> {
    return this.guard(async () => {
      const body: InvoicingCheckWrite = { deliveryNoteIds: [...deliveryNoteIds], invoiceId };
      const check = await firstValueFrom(
        this.http.post<InvoicingCheckRead>(
          `${companyPath(companyId)}/invoices/from-delivery-notes/check`,
          body,
        ),
      );
      if (check.rows === undefined || check.included === undefined || check.skipped === undefined)
        throw new Error('An invoicing check came without its rows.');
      return {
        rows: check.rows.map((row) => ({
          id: row.id,
          number: row.number,
          customerName: row.customerName,
          included: row.outcome === 'included',
          reason: row.reason,
        })),
        included: check.included,
        skipped: check.skipped,
        customerId: check.customerId ?? null,
        establishmentId: check.establishmentId ?? null,
      };
    });
  }

  async invoice(
    companyId: string,
    deliveryNoteIds: readonly string[],
    quantities?: Readonly<Record<string, string>>,
    intoDraftId?: string,
  ): Promise<string> {
    const body: InvoiceFromDeliveryNotesInvoiceFromDeliveryNotesWrite = {
      deliveryNoteIds: [...deliveryNoteIds],
      ...(quantities === undefined ? {} : { quantities: { ...quantities } }),
    };
    return this.guard(async () => {
      const draft = await firstValueFrom(
        this.http.post<InvoiceFromDeliveryNotesInvoiceResourceInvoiceRead>(
          intoDraftId === undefined
            ? `${companyPath(companyId)}/invoices/from-delivery-notes`
            : `${companyPath(companyId)}/invoices/${encodeURIComponent(intoDraftId)}/delivery-notes`,
          body,
        ),
      );
      return draft.id ?? '';
    });
  }

  /** The drafts of this customer and establishment the note could be added to (`InvoicesApi.openDraftsOf`). */
  async draftsOf(
    companyId: string,
    customerId: string,
    establishmentId: string | null,
  ): Promise<InvoiceDraftOption[]> {
    return this.guard(() => this.invoices.openDraftsOf(companyId, customerId, establishmentId));
  }

  /** Where a note's PDF is downloaded from, with the session the browser already has. */
  pdfUrl(companyId: string, id: string): string {
    return `${notePath(companyId, id)}/pdf`;
  }

  private async step(
    companyId: string,
    id: string,
    action: 'validate' | 'validate-and-deliver' | 'deliver' | 'cancel',
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
      throw refusedFor(error, new DeliveryNotesRefused(codeOf(error)));
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
      return isRecordChanged(error.error) ? 'record_changed' : 'conflict';
    default:
      // A customer deactivated since the note was started, named by the field the API refused.
      return refusedField(error) === 'customerId' ? 'customer_unavailable' : 'invalid';
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
  for (const status of search.status) params = params.append('status[]', status);
  params = narrowing(params, search);
  if (search.order !== null)
    params = params.set(`order[${search.order.key}]`, search.order.direction);
  return params;
}

/** What narrows a list whatever the status: the customers and the ends of the intervals, as the API names them. */
function narrowing(params: HttpParams, search: DeliveryNoteSearch): HttpParams {
  let next = params;
  for (const id of search.customerIds) next = next.append('customerId[]', id);
  for (const [key, value] of Object.entries(search.intervals)) {
    next = next.set(apiRangeKey(key), value);
  }
  return next;
}

/** The chips narrow by status themselves, and a count has no page or order. */
function toCountParams(search: DeliveryNoteSearch): HttpParams {
  let params = new HttpParams();
  if (search.q.trim() !== '') params = params.set('q', search.q.trim());
  return narrowing(params, search);
}

function toNote(
  raw: DeliveryNoteDeliveryNoteRead | DeliveryNoteJsonldDeliveryNoteRead,
): DeliveryNoteRow {
  return {
    id: raw.id ?? '',
    version: raw.version ?? 0,
    number: raw.number ?? null,
    status: DELIVERY_NOTE_STATUSES.find((status) => status === raw.status) ?? 'draft',
    customerId: raw.customerId ?? '',
    establishmentId: raw.establishmentId ?? null,
    recordedCustomerName: raw.customerSnapshot?.name ?? null,
    customerName: raw.customerName ?? '',
    issueDate: raw.issueDate ?? null,
    deliveryDate: raw.deliveryDate ?? null,
    deliveredAt: raw.deliveredAt ?? null,
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
      productReference: line.productReference ?? null,
      productName: line.productName ?? null,
      productTracking: line.productTracking == null ? null : trackingOf(line.productTracking),
      lotCode: line.lotCode ?? null,
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
    invoices: (raw.invoices ?? []).map((invoice) => ({
      invoiceId: invoice.invoiceId,
      number: invoice.number,
      status: invoice.status,
    })),
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
