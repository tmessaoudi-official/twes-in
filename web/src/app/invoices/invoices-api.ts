// SPDX-License-Identifier: AGPL-3.0-or-later

import { exportAddress, type ExportFormat } from '../shared/list/export-address';
import { HttpClient, HttpContext, HttpErrorResponse, HttpParams } from '@angular/common/http';
import type { PreviewBody } from '../shared/documents/document-figures';
import { SILENT } from '../shared/feedback/activity-interceptor';
import { isPeriodClosed } from '../shared/documents/period-closed';
import { isRecordChanged } from '../shared/errors/record-changed';
import { inject, Injectable, Injector } from '@angular/core';
import type { FieldRefusal } from '../shared/form/field-refusals';
import { formRefusalsIn } from '../shared/form/form-refusals-api';
import { firstValueFrom } from 'rxjs';
import { filenameOf } from '../shared/files/save-file';
import type {
  InvoiceBulkCheckInvoiceBulkCheckReadValidationInvoiceBulkCheckWrite as InvoiceBulkCheckRead,
  InvoiceBulkCheckInvoiceBulkCheckWriteValidationInvoiceBulkCheckWrite as InvoiceBulkCheckWrite,
  InvoiceBulkPaymentInvoiceBulkPaymentReadValidationInvoiceBulkPaymentWrite as InvoiceBulkPaymentRead,
  InvoiceBulkPaymentInvoiceBulkPaymentWriteValidationInvoiceBulkPaymentWrite as InvoiceBulkPaymentWrite,
  InvoiceDocumentPreviewDocumentPreviewValidationInvoiceWrite,
  ApiCompaniesCompanyIdinvoicesGetCollectionResponse,
  CustomerCreditBalanceCustomerCreditBalanceRead,
  InvoiceInvoiceCreditValidationInvoiceCredit as InvoiceInvoiceCredit,
  InvoiceInvoiceReceiveValidationInvoiceReceive as InvoiceReceiveBody,
  InvoiceInvoiceRead,
  InvoiceJsonldInvoiceRead,
  InvoiceInvoiceWriteRecordReviseValidationInvoiceWriteRecordRevise as InvoiceInvoiceRevise,
  InvoiceInvoiceWriteValidationInvoiceWrite as InvoiceInvoiceWrite,
  InvoiceCustomerPickInvoiceCustomerPickRead,
  InvoiceNextNumberInvoiceNextNumberRead,
  InvoiceOptionsInvoiceOptionsRead,
  InvoiceProductPickInvoiceProductPickRead,
  InvoiceReceiverPickInvoiceReceiverPickRead,
  InvoiceStatusCountsInvoiceStatusCountsRead,
  InvoiceSummaryInvoiceSummaryRead,
  PaymentPaymentWriteValidationPaymentWrite as PaymentPaymentWrite,
  ProductPriceProductPriceRead,
} from '../api/types.gen';
import type { ListPage } from '../shared/list/list-types';
import type { ReceiverChoice, ReceiverContact } from '../shared/receivers/receiver';
import { trackingOf } from '../products/products-types';
import { type PickAsked, pickParams } from '../shared/form/pick-api';
import { apiRangeKey } from '../shared/list/list-filters';
import {
  AGING_BUCKETS,
  type BulkAction,
  type BulkCheck,
  type BulkPaid,
  type BulkPayment,
  type BulkPdfFormat,
  type AgingAmount,
  INVOICE_STATUSES,
  type IssuePreview,
  OPERATION_CATEGORIES,
  type OperationCategory,
  type InvoiceDraftOption,
  type InvoiceInput,
  type InvoiceOptions,
  type InvoiceRow,
  type CreditExcessTo,
  type FacturXAnswer,
  type FacturXFormat,
  type FacturXRefusalCode,
  type InvoiceSearch,
  type InvoicesError,
  type InvoiceStatusCounts,
  type InvoiceSummary,
  PAYMENT_METHODS,
  type CustomerOption,
  type ProductOption,
  type OverpaymentInput,
  type PaymentInput,
  type ResolvedPrice,
} from './invoices-types';
import { refusedFor } from '../shared/errors/said-already';

/** Thrown when the API refuses; carries the code the UI translates. */
export class InvoicesRefused extends Error {
  /** `refusals`: each field a 422 named, with why, which the form says under it. */
  constructor(
    readonly code: InvoicesError,
    readonly refusals: readonly FieldRefusal[] = [],
  ) {
    super(code);
  }
}

/** The HTTP edge of the invoices feature: the only code here that knows endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class InvoicesApi {
  private readonly http = inject(HttpClient);
  private readonly injector = inject(Injector);

  /** What the invoice form offers: the currency, and the establishments, customers, products, units and taxes. */
  /**
   * The number a draft would carry if it were issued now, and where the law asks, what it would state its operations
   * are, said on the question before issuing; null when it cannot be said (the document is no longer a draft, or its
   * establishment cannot number it today), which issuing then says.
   */
  async nextNumber(companyId: string, id: string): Promise<IssuePreview | null> {
    try {
      const answer = await firstValueFrom(
        this.http.get<InvoiceNextNumberInvoiceNextNumberRead>(
          `${companyPath(companyId)}/invoices/${encodeURIComponent(id)}/next-number`,
        ),
      );
      return { number: answer.number, operationCategory: operationsOf(answer.operationCategory) };
    } catch (error) {
      if (error instanceof HttpErrorResponse && error.status === 409) return null;
      throw refusedFor(error, new InvoicesRefused(codeOf(error)));
    }
  }

  async options(companyId: string): Promise<InvoiceOptions> {
    return this.guard(async () =>
      toOptions(
        await firstValueFrom(
          this.http.get<InvoiceOptionsInvoiceOptionsRead>(
            `${companyPath(companyId)}/invoice-options`,
          ),
        ),
      ),
    );
  }

  /**
   * The few customers or products a person means, or — given ids — exactly the records a document already names,
   * whether or not they are still offered. The catalogue is never read whole (docs/SPEC.md § 7, 2026-09-17, ruling 3).
   */
  async pickCustomers(companyId: string, asked: PickAsked): Promise<CustomerOption[]> {
    return this.guard(async () => {
      const rows = await firstValueFrom(
        this.http.get<InvoiceCustomerPickInvoiceCustomerPickRead[]>(
          `${companyPath(companyId)}/invoice-options/customers`,
          { params: pickParams(asked) },
        ),
      );
      return rows.map((customer) => ({
        id: customer.id ?? '',
        number: customer.number,
        name: customer.name,
        excludedFamilies: [...customer.excludedFamilies],
        defaultDiscountRate: customer.defaultDiscountRate,
        defaultTaxComponentIds: [...customer.defaultTaxComponentIds],
      }));
    });
  }

  /** The customer's contacts an invoice offers to name who received its goods, under the invoice's own permission. */
  async receivers(companyId: string, customerId: string): Promise<ReceiverContact[]> {
    return this.guard(async () => {
      const rows = await firstValueFrom(
        this.http.get<InvoiceReceiverPickInvoiceReceiverPickRead[]>(
          `${companyPath(companyId)}/invoice-options/customers/${encodeURIComponent(customerId)}/receivers`,
        ),
      );
      return rows.map((row) => ({ contactId: row.contactId ?? '', name: row.name }));
    });
  }

  async pickProducts(companyId: string, asked: PickAsked): Promise<ProductOption[]> {
    return this.guard(async () => {
      const rows = await firstValueFrom(
        this.http.get<InvoiceProductPickInvoiceProductPickRead[]>(
          `${companyPath(companyId)}/invoice-options/products`,
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

  /**
   * The net unit price a sale of the product starts at for that customer and quantity, and the price list that set it
   * (null for the shelf price). A price that cannot be read, such as a module switched off meanwhile, is null: the
   * line keeps the price it has.
   */
  async productPrice(
    companyId: string,
    productId: string,
    customerId: string | null,
    quantity: string,
  ): Promise<ResolvedPrice | null> {
    let params = new HttpParams().set('quantity', quantity);
    if (customerId !== null) params = params.set('customerId', customerId);
    try {
      const read = await firstValueFrom(
        this.http.get<ProductPriceProductPriceRead>(
          `${companyPath(companyId)}/products/${encodeURIComponent(productId)}/price`,
          { params },
        ),
      );
      return { unitPriceNet: read.unitPriceNet, priceListName: read.priceListName ?? null };
    } catch {
      return null;
    }
  }

  /** The home page's figures, worked out by the API on the company's day. */
  async summary(companyId: string): Promise<InvoiceSummary> {
    return this.guard(async () =>
      toSummary(
        await firstValueFrom(
          this.http.get<InvoiceSummaryInvoiceSummaryRead>(
            `${companyPath(companyId)}/invoice-summary`,
          ),
        ),
      ),
    );
  }

  /** One page of the company's invoices and credit notes, as the API searched, narrowed and sorted them. */
  async invoices(companyId: string, search: InvoiceSearch): Promise<ListPage<InvoiceRow>> {
    return this.guard(async () => {
      const page = await firstValueFrom(
        this.http.get<ApiCompaniesCompanyIdinvoicesGetCollectionResponse>(invoicePath(companyId), {
          headers: { Accept: 'application/ld+json' },
          params: toSearchParams(search),
        }),
      );
      if (page.totalItems === undefined)
        throw new Error('A page of invoices came without its total.');
      return { rows: page.member.map(toInvoice), total: page.totalItems };
    });
  }

  /**
   * The drafts of this customer and establishment another document's lines could be added to, newest work first as the
   * API lists them: invoices only, never a deposit or a credit note, and a draft of another establishment left out,
   * because its series numbers it. At most the first page.
   */
  async openDraftsOf(
    companyId: string,
    customerId: string,
    establishmentId: string | null,
  ): Promise<InvoiceDraftOption[]> {
    const page = await this.invoices(companyId, {
      page: 1,
      itemsPerPage: 20,
      q: '',
      status: ['draft'],
      documentType: ['invoice'],
      customerIds: [customerId],
      intervals: {},
      order: null,
    });
    return page.rows
      .filter((row) => row.establishmentId === establishmentId)
      .map((row) => ({
        id: row.id,
        total: row.total,
        lineCount: row.lines.length,
        customerReference: row.customerReference,
      }));
  }

  /** What each status chip would list, counted by the API under the search's words, kind and customer. */
  async statusCounts(companyId: string, search: InvoiceSearch): Promise<InvoiceStatusCounts> {
    return this.guard(async () => {
      const counts = await firstValueFrom(
        this.http.get<InvoiceStatusCountsInvoiceStatusCountsRead>(
          `${companyPath(companyId)}/invoice-status-counts`,
          { params: toCountParams(search) },
        ),
      );
      if (counts.all === undefined || counts.statuses === undefined)
        throw new Error('Status counts came without their figures.');
      return { all: counts.all, statuses: { ...counts.statuses } };
    });
  }

  async invoice(companyId: string, id: string): Promise<InvoiceRow> {
    return this.guard(async () =>
      toInvoice(
        await firstValueFrom(this.http.get<InvoiceInvoiceRead>(invoicePath(companyId, id))),
      ),
    );
  }

  /** 422 naming what the API refused. */
  async create(companyId: string, input: InvoiceInput): Promise<InvoiceRow> {
    return this.guard(async () =>
      toInvoice(
        await firstValueFrom(
          this.http.post<InvoiceInvoiceRead>(invoicePath(companyId), toBody(input)),
        ),
      ),
    );
  }

  /**
   * 409 once the document is no longer a draft; `version` is the one the form was opened at, and a later one saved
   * elsewhere answers `record_changed`.
   */
  async revise(
    companyId: string,
    id: string,
    input: InvoiceInput,
    version: number | null,
  ): Promise<InvoiceRow> {
    const body: InvoiceInvoiceRevise = { ...toBody(input), version };
    return this.guard(async () =>
      toInvoice(
        await firstValueFrom(this.http.put<InvoiceInvoiceRead>(invoicePath(companyId, id), body)),
      ),
    );
  }

  /**
   * What the document would come to with what is typed, kept nowhere: a draft's own path when it exists, so a credit
   * note is worked out as one. Quiet, since typing is not an action; a refusal is thrown, as any other answer.
   */
  async preview(companyId: string, id: string | null, input: InvoiceInput): Promise<PreviewBody> {
    const path =
      id === null ? `${invoicePath(companyId)}/preview` : `${invoicePath(companyId, id)}/preview`;
    return firstValueFrom(
      this.http.post<InvoiceDocumentPreviewDocumentPreviewValidationInvoiceWrite>(
        path,
        toBody(input),
        {
          context: new HttpContext().set(SILENT, true),
        },
      ),
    );
  }

  /** Numbers a draft and fixes what it says; 409 when its establishment cannot number it, 422 without lines. */
  async issue(companyId: string, id: string, excessTo?: CreditExcessTo): Promise<InvoiceRow> {
    return this.step(companyId, id, 'issue', null, excessTo);
  }

  /** Cancels a draft; an issued document is never cancelled (409). */
  async cancel(companyId: string, id: string): Promise<InvoiceRow> {
    return this.step(companyId, id, 'cancel');
  }

  /** Drafts a credit note for an issued invoice from its lines, stating why; the answer is the credit note. */
  async creditNote(companyId: string, id: string, reason: string): Promise<InvoiceRow> {
    const body: InvoiceInvoiceCredit = { creditNoteReason: reason };
    return this.step(companyId, id, 'credit-notes', body);
  }

  /**
   * Who received the goods an issued invoice handed over itself, put right, or nobody; its print as issued keeps the
   * name it was issued with. 409 on a draft, 422 naming `receivedBy` as a save refuses it.
   */
  async correctReceiver(
    companyId: string,
    id: string,
    receivedBy: ReceiverChoice | null,
  ): Promise<InvoiceRow> {
    const body: InvoiceReceiveBody = { receivedBy };
    return this.step(companyId, id, 'receiver', body);
  }

  /**
   * « Livrer » or « Livrer une partie »: a draft delivery note of the issued invoice's goods, every line for what is left
   * of it, or with `quantities` (by invoice line id) those lines alone; answers the note's id. 409 when the invoice is
   * not issued, is a credit note or a deposit, or has nothing left; 422 when a quantity is more than is left.
   */
  async deliver(
    companyId: string,
    id: string,
    quantities: Readonly<Record<string, string>> | null,
  ): Promise<string> {
    try {
      const body: { quantities: Record<string, string> | null } = {
        quantities: quantities === null ? null : { ...quantities },
      };
      // The answer is the note as its own screen reads it; only its id is wanted here.
      const note = await firstValueFrom(
        this.http.post<{ id?: string }>(`${invoicePath(companyId, id)}/deliveries`, body),
      );
      if (note.id === undefined) throw new Error('A delivery note came without its id.');
      return note.id;
    } catch (error) {
      throw refusedFor(
        error,
        new InvoicesRefused(deliveryCodeOf(error), await formRefusalsIn(this.injector, error)),
      );
    }
  }

  /** Copies a document into a new draft; the answer is the copy. 409 for a credit note. */
  async duplicate(companyId: string, id: string): Promise<InvoiceRow> {
    return this.step(companyId, id, 'duplicate');
  }

  /** 422 for an amount above what is due or a day outside the issue day to today, 409 on a document not issued. */
  async recordPayment(companyId: string, id: string, payment: PaymentInput): Promise<void> {
    const body: PaymentPaymentWrite = { ...payment };
    await this.guard(() =>
      firstValueFrom(this.http.post(`${invoicePath(companyId, id)}/payments`, body)),
    );
  }

  /** What the customer has to their credit, as the API's decimal string; 404 for a customer the member may not see money of. */
  async customerCredit(companyId: string, customerId: string): Promise<string> {
    const raw = await this.guard(() =>
      firstValueFrom(
        this.http.get<CustomerCreditBalanceCustomerCreditBalanceRead>(
          `${companyPath(companyId)}/customers/${encodeURIComponent(customerId)}/credit-balance`,
        ),
      ),
    );
    return raw.balance ?? '0';
  }

  /** Pays the invoice from the customer's credit, as much as fits; 422 when there is nothing to apply, 409 when not issued. */
  async applyCredit(companyId: string, id: string): Promise<void> {
    await this.guard(() =>
      firstValueFrom(this.http.post(`${invoicePath(companyId, id)}/apply-credit`, {})),
    );
  }

  /** Keeps what the customer paid beyond this invoice to their credit; the API answers nothing. */
  async overpay(companyId: string, id: string, overpayment: OverpaymentInput): Promise<void> {
    await this.guard(() =>
      firstValueFrom(this.http.post(`${invoicePath(companyId, id)}/overpayments`, overpayment)),
    );
  }

  async deletePayment(companyId: string, id: string, paymentId: string): Promise<void> {
    await this.guard(() =>
      firstValueFrom(
        this.http.delete(`${invoicePath(companyId, id)}/payments/${encodeURIComponent(paymentId)}`),
      ),
    );
  }

  /** Where a document's PDF is downloaded from, with the session the browser already has. */
  /** Where the invoices and credit notes the search finds are downloaded as a file, every page of them. */
  exportUrl(companyId: string, search: InvoiceSearch, format: ExportFormat): string {
    return exportAddress(companyId, 'invoices', toSearchParams(search), format);
  }

  /** Where the selected invoices alone are downloaded as a file, whatever the list's words and filters. */
  selectedExportUrl(companyId: string, ids: readonly string[], format: ExportFormat): string {
    let params = new HttpParams();
    for (const id of ids) params = params.append('id[]', id);
    return exportAddress(companyId, 'invoices', params, format);
  }

  /** The selected invoices printed, in the order chosen; drafts and cancelled ones only once the address asks for them. */
  selectedPdfUrl(companyId: string, ids: readonly string[], format: BulkPdfFormat): string {
    let params = new HttpParams();
    for (const id of ids) params = params.append('id[]', id);
    return `/api/companies/${encodeURIComponent(companyId)}/exports/invoices.${format}?${params.toString()}`;
  }

  /** What a bulk action would do with each selected invoice, checked by the API before it runs; nothing changes. */
  async bulkCheck(
    companyId: string,
    action: BulkAction,
    ids: readonly string[],
    date: string | null = null,
  ): Promise<BulkCheck> {
    return this.guard(async () => {
      const body: InvoiceBulkCheckWrite = { action, ids: [...ids], date };
      const check = await firstValueFrom(
        this.http.post<InvoiceBulkCheckRead>(`${invoicePath(companyId)}/bulk-check`, body),
      );
      if (check.rows === undefined || check.included === undefined || check.skipped === undefined)
        throw new Error('A bulk check came without its rows.');
      return {
        rows: check.rows.map((row) => ({
          id: row.id,
          number: row.number,
          customerName: row.customerName,
          included: row.outcome === 'included',
          reason: row.reason,
          amount: row.amount,
        })),
        included: check.included,
        skipped: check.skipped,
      };
    });
  }

  /** Records every payment of a selection or none: 409 once an invoice changed since its check, 422 on a closed day. */
  async bulkPay(companyId: string, payment: BulkPayment): Promise<BulkPaid> {
    return this.guard(async () => {
      const body: InvoiceBulkPaymentWrite = { ...payment, rows: [...payment.rows] };
      const paid = await firstValueFrom(
        this.http.post<InvoiceBulkPaymentRead>(`${invoicePath(companyId)}/bulk-payments`, body),
      );
      if (paid.recorded === undefined || paid.total === undefined)
        throw new Error('A bulk payment came without what it recorded.');
      return { recorded: paid.recorded, total: paid.total };
    });
  }

  pdfUrl(companyId: string, id: string): string {
    return `${invoicePath(companyId, id)}/pdf`;
  }

  /**
   * An issued document's Factur-X, fetched rather than linked, so a refusal is said in the page: 409 for a draft, 422
   * with every datum the document lacks. Any other failure is refused as usual.
   */
  async facturX(companyId: string, id: string, format: FacturXFormat): Promise<FacturXAnswer> {
    try {
      const response = await firstValueFrom(
        this.http.get(`${invoicePath(companyId, id)}/factur-x.${format}`, {
          observe: 'response',
          responseType: 'blob',
        }),
      );
      return {
        kind: 'file',
        file: response.body ?? new Blob([]),
        filename: filenameOf(response.headers.get('Content-Disposition')) ?? `factur-x.${format}`,
      };
    } catch (error) {
      if (
        error instanceof HttpErrorResponse &&
        (error.status === 409 || error.status === 422) &&
        error.error instanceof Blob
      ) {
        return toFacturXRefusal(JSON.parse(await error.error.text()) as RawFacturXRefusal);
      }
      throw refusedFor(error, new InvoicesRefused(codeOf(error)));
    }
  }

  /** A duplicate (the document as issued) or an up-to-date copy (with what was paid since), printed on request. */
  pdfCopyUrl(companyId: string, id: string, kind: 'duplicate' | 'current'): string {
    return `${invoicePath(companyId, id)}/pdf/${kind}`;
  }

  private async step(
    companyId: string,
    id: string,
    action: 'issue' | 'cancel' | 'credit-notes' | 'duplicate' | 'receiver',
    body: InvoiceInvoiceCredit | InvoiceReceiveBody | null = null,
    excessTo?: CreditExcessTo,
  ): Promise<InvoiceRow> {
    const params =
      excessTo === undefined ? new HttpParams() : new HttpParams().set('excessTo', excessTo);
    return this.guard(async () =>
      toInvoice(
        await firstValueFrom(
          this.http.post<InvoiceInvoiceRead>(`${invoicePath(companyId, id)}/${action}`, body, {
            params,
          }),
        ),
      ),
    );
  }

  private async guard<T>(call: () => Promise<T>): Promise<T> {
    try {
      return await call();
    } catch (error) {
      throw refusedFor(
        error,
        new InvoicesRefused(codeOf(error), await formRefusalsIn(this.injector, error)),
      );
    }
  }
}

export function codeOf(error: unknown): InvoicesError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return 'network';
  }
  switch (error.status) {
    case 404:
      return 'not_found';
    case 409:
      return isRecordChanged(error.error) ? 'record_changed' : 'conflict';
    default:
      // A credit note that gives back money already paid is refused until it says where that money goes.
      if (isExcessRefusal(error)) return 'excess_to';
      if (isPeriodClosed(error)) return 'period_closed';
      return fieldCode(refusedField(error));
  }
}

/** « Livrer » refused: any 422 is a quantity more than is left of its line. */
function deliveryCodeOf(error: unknown): InvoicesError {
  return error instanceof HttpErrorResponse && error.status === 422
    ? 'delivery_refused'
    : codeOf(error);
}

/** The settings a legal mention may wait for, as the API names them, and what the screen says for each. */
const MENTION_DATA: Readonly<Record<string, InvoicesError>> = {
  'document.late_payment_rate': 'missing_late_payment_rate',
  'document.exemption_reference': 'missing_exemption_reference',
};

/** The parties issuing may find unnamed, as the API names them, and what the screen says for each. */
const PARTY_IDENTITY: Readonly<Record<string, InvoicesError>> = {
  seller_identity: 'missing_seller_identity',
  customer_identity: 'missing_customer_identity',
};

interface RawFacturXRefusal {
  code: FacturXRefusalCode;
  params?: Record<string, string | number>;
  gaps?: { code: string; params?: Record<string, string | number | string[]> }[];
}

function toFacturXRefusal(raw: RawFacturXRefusal): FacturXAnswer {
  return {
    kind: 'refused',
    code: raw.code,
    params: { ...(raw.params ?? {}) },
    gaps: (raw.gaps ?? []).map((gap) => ({ code: gap.code, params: { ...(gap.params ?? {}) } })),
  };
}

function fieldCode(field: string | null): InvoicesError {
  if (field === 'customerId') return 'customer_unavailable';
  if (field === 'operationCategory') return 'missing_operation_category';
  if (field === 'deposit') return 'deposit_beyond_quote';
  if (field !== null && field in PARTY_IDENTITY) return PARTY_IDENTITY[field];
  if (field === null) return 'invalid';
  return MENTION_DATA[field] ?? (field.startsWith('mention.') ? 'missing_mention' : 'invalid');
}

/**
 * The field a 422 names before its colon (`customerId: …`), the API's stable word for what it refused; its message is
 * English prose and is never matched.
 */
export function refusedField(error: HttpErrorResponse): string | null {
  const detail = (error.error as { detail?: unknown } | null)?.detail;
  if (error.status !== 422 || typeof detail !== 'string') return null;
  const colon = detail.indexOf(':');
  return colon > 0 ? detail.slice(0, colon) : null;
}

function isExcessRefusal(error: HttpErrorResponse): boolean {
  const detail = (error.error as { detail?: unknown } | null)?.detail;
  return error.status === 422 && typeof detail === 'string' && detail.startsWith('excessTo:');
}

const companyPath = (companyId: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}`;

const invoicePath = (companyId: string, id?: string): string =>
  `${companyPath(companyId)}/invoices${id === undefined ? '' : `/${encodeURIComponent(id)}`}`;

const ids = (values: readonly (string | null | undefined)[] | null | undefined): string[] =>
  (values ?? []).filter((id): id is string => typeof id === 'string');

function toSearchParams(search: InvoiceSearch): HttpParams {
  let params = new HttpParams().set('page', search.page).set('itemsPerPage', search.itemsPerPage);
  if (search.q.trim() !== '') params = params.set('q', search.q.trim());
  for (const status of search.status) params = params.append('status[]', status);
  params = narrowing(params, search);
  if (search.order !== null)
    params = params.set(`order[${search.order.key}]`, search.order.direction);
  return params;
}

/** What narrows a list whatever the status: kinds, customers and the ends of the intervals, each as the API names it. */
function narrowing(params: HttpParams, search: InvoiceSearch): HttpParams {
  let next = params;
  for (const type of search.documentType) next = next.append('documentType[]', type);
  for (const id of search.customerIds) next = next.append('customerId[]', id);
  for (const [key, value] of Object.entries(search.intervals)) {
    next = next.set(apiRangeKey(key), value);
  }
  return next;
}

/** The search as a count reads it: all but the status, which the chips count themselves, and the page and order. */
function toCountParams(search: InvoiceSearch): HttpParams {
  let params = new HttpParams();
  if (search.q.trim() !== '') params = params.set('q', search.q.trim());
  return narrowing(params, search);
}

function toInvoice(raw: InvoiceInvoiceRead | InvoiceJsonldInvoiceRead): InvoiceRow {
  return {
    id: raw.id ?? '',
    // Every read carries it; a 0 would be refused by the API rather than taken for a version.
    version: raw.version ?? 0,
    type: raw.type === 'credit_note' ? 'credit_note' : 'invoice',
    correctsInvoiceId: raw.correctsInvoiceId ?? null,
    creditNoteReason: raw.creditNoteReason ?? null,
    deposit: raw.deposit ?? false,
    quoteId: raw.quoteId ?? null,
    deliveryNotes: (raw.deliveryNotes ?? []).map((note) => ({
      deliveryNoteId: note.deliveryNoteId,
      number: note.number,
    })),
    deliveredBy: (raw.deliveredBy ?? []).map(({ deliveryNoteId, number, status }) => ({
      deliveryNoteId,
      number: number ?? null,
      status,
    })),
    delivery: raw.delivery ?? 'none',
    deliveries: (raw.deliveries ?? []).map((line) => {
      const billed = (raw.lines ?? []).find((each) => each.id === line.lineId);
      return {
        lineId: line.lineId,
        description: billed?.description ?? billed?.productName ?? '',
        quantity: line.quantity,
        returned: line.returned,
        onNotes: line.onNotes,
        delivered: line.delivered,
        left: line.left,
      };
    }),
    number: raw.number ?? null,
    status: INVOICE_STATUSES.find((status) => status === raw.status) ?? 'draft',
    customerId: raw.customerId ?? '',
    recordedCustomerName: raw.customerSnapshot?.name ?? null,
    customerName: raw.customerName ?? '',
    establishmentId: raw.establishmentId ?? null,
    issueDate: raw.issueDate ?? null,
    dueDate: raw.dueDate ?? null,
    supplyDate: raw.supplyDate ?? null,
    paymentTermsDays: raw.paymentTermsDays ?? null,
    customerReference: raw.customerReference ?? null,
    notesPrinted: raw.notesPrinted ?? null,
    notesInternal: raw.notesInternal ?? null,
    receivedBy: raw.receivedBy?.name
      ? { name: raw.receivedBy.name, contactId: raw.receivedBy.contactId ?? null }
      : null,
    receiverRule: raw.receiverRule ?? null,
    discountAmount: raw.discountAmount ?? null,
    operationCategory: operationsOf(raw.operationCategory),
    vatOnDebits: raw.vatOnDebits ?? null,
    documentTaxComponentIds:
      raw.documentTaxComponentIds === null || raw.documentTaxComponentIds === undefined
        ? null
        : ids(raw.documentTaxComponentIds),
    lines: (raw.lines ?? []).map((line) => ({
      productId: line.productId ?? null,
      description: line.description ?? '',
      quantity: line.quantity,
      unitId: line.unitId ?? '',
      unitPriceNet: line.unitPriceNet ?? '',
      discountRate: line.discountRate ?? null,
      discountAmount: line.discountAmount ?? null,
      taxComponentIds: ids(line.taxComponentIds),
      sourceDeliveryNoteLineId: line.sourceDeliveryNoteLineId ?? null,
      sourceLeft: line.sourceLeft ?? null,
      productReference: line.productReference ?? null,
      productName: line.productName ?? null,
      productTracking: line.productTracking == null ? null : trackingOf(line.productTracking),
      lotCode: line.lotCode ?? null,
      returned: line.returned ?? false,
      deductsInvoiceId: line.deductsInvoiceId ?? null,
      section: line.section ?? null,
      creditedLineId: line.creditedLineId ?? null,
      net: line.net ?? '',
    })),
    subtotalNet: raw.subtotalNet ?? '0',
    documentDiscount: raw.documentDiscount ?? '0',
    savings: raw.savings ?? null,
    totalNet: raw.totalNet ?? '0',
    taxes: (raw.taxes ?? []).map(({ code, rate, base, amount }) => ({ code, rate, base, amount })),
    totalTax: raw.totalTax ?? '0',
    fixedTaxes: (raw.fixedTaxes ?? []).map(({ code, amount }) => ({ code, amount })),
    total: raw.total ?? '0',
    withholdings: (raw.withholdings ?? []).map(({ code, rate, base, amount }) => ({
      code,
      rate,
      base,
      amount,
    })),
    netToPay: raw.netToPay ?? '0',
    amountDue: raw.amountDue ?? '0',
    amountPaid: raw.amountPaid ?? '0',
    amountCredited: raw.amountCredited ?? '0',
    payments: (raw.payments ?? []).map((payment) => ({
      id: payment.id,
      date: payment.date,
      amount: payment.amount,
      method: PAYMENT_METHODS.find((method) => method === payment.method) ?? 'other',
      reference: payment.reference,
      notes: payment.notes,
    })),
  };
}

function toSummary(raw: InvoiceSummaryInvoiceSummaryRead): InvoiceSummary {
  return {
    currency: raw.currency ?? '',
    currencyScale: raw.currencyScale ?? 2,
    today: raw.today ?? '',
    outstanding: raw.outstanding ?? '0',
    notYetDue: raw.notYetDue ?? '0',
    overdue: raw.overdue ?? '0',
    overdueCount: raw.overdueCount ?? 0,
    oldestOverdueDays: raw.oldestOverdueDays ?? null,
    aging: (raw.aging ?? []).flatMap(({ bucket, amount, count }): AgingAmount[] => {
      const known = AGING_BUCKETS.find((each) => each === bucket);
      return known === undefined ? [] : [{ bucket: known, amount, count }];
    }),
    toChase: (raw.toChase ?? []).map(
      ({ invoiceId, number, customerName, dueDate, amountDue, daysLate }) => ({
        invoiceId,
        number,
        customerName,
        dueDate,
        amountDue,
        daysLate,
      }),
    ),
    toChaseCount: raw.toChaseCount ?? 0,
    toChaseAmount: raw.toChaseAmount ?? '0',
    collected: (raw.collected ?? []).map(({ month, amount }) => ({ month, amount })),
    vat: (raw.vat ?? []).map(({ code, rate, name, amount }) => ({
      code,
      rate,
      name: name ?? null,
      amount,
    })),
    vatTotal: raw.vatTotal ?? '0',
    invoicedMonth: raw.invoicedMonth ?? '0',
    invoicedLastMonth: raw.invoicedLastMonth ?? '0',
    collectedMonth: raw.collectedMonth ?? '0',
    collectedLastMonth: raw.collectedLastMonth ?? '0',
    margin: raw.margin ?? null,
    marginLastMonth: raw.marginLastMonth ?? null,
    marginBasis: raw.marginBasis ?? null,
    costsVisible: raw.costsVisible ?? false,
    withheldMonth: raw.withheldMonth ?? '0',
    withheldLastMonth: raw.withheldLastMonth ?? '0',
  };
}

function toBody(input: InvoiceInput): InvoiceInvoiceWrite {
  return {
    ...input,
    documentTaxComponentIds:
      input.documentTaxComponentIds === null ? null : [...input.documentTaxComponentIds],
    lines: input.lines.map((line) => ({ ...line, taxComponentIds: [...line.taxComponentIds] })),
  };
}

function toOptions(raw: InvoiceOptionsInvoiceOptionsRead): InvoiceOptions {
  return {
    currency: raw.currency ?? '',
    currencyScale: raw.currencyScale ?? 2,
    establishments: (raw.establishments ?? []).map(({ id, code, name, isDefault }) => ({
      id,
      code,
      name,
      isDefault,
    })),
    units: (raw.units ?? []).map(({ id, code, name, decimals }) => ({ id, code, name, decimals })),
    taxes: (raw.taxes ?? []).map((tax) => ({
      id: tax.id,
      code: tax.code,
      name: tax.name,
      kind: tax.kind,
      family: tax.family,
      rate: tax.rate,
      amount: tax.amount,
      threshold: tax.threshold,
      isDefault: tax.isDefault,
    })),
    operationCategory: raw.operationCategory ?? false,
  };
}

function operationsOf(raw: string | null | undefined): OperationCategory | null {
  return OPERATION_CATEGORIES.find((category) => category === raw) ?? null;
}
