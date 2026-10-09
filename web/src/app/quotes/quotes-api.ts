// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpContext, HttpErrorResponse, HttpParams } from '@angular/common/http';
import type { PreviewBody } from '../shared/documents/document-figures';
import { SILENT } from '../shared/feedback/activity-interceptor';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  ApiCompaniesCompanyIdquotesGetCollectionResponse,
  QuoteAttachmentQuoteAttachmentRead,
  QuoteDocumentPreviewDocumentPreviewValidationQuoteWrite,
  QuoteCustomerPickQuoteCustomerPickRead,
  QuoteJsonldQuoteRead,
  QuoteOptionsQuoteOptionsRead,
  QuoteProductPickQuoteProductPickRead,
  QuoteQuoteAnswerQuoteRefuseValidationQuoteAnswerQuoteRefuse as QuoteRefuseBody,
  QuoteQuoteRead,
  QuoteQuoteWriteValidationQuoteWrite as QuoteWriteBody,
  QuoteStatusCountsQuoteStatusCountsRead,
} from '../api/types.gen';
import { InvoicesApi, refusedField } from '../invoices/invoices-api';
import type {
  CustomerOption,
  InvoiceOptions,
  ProductOption,
  ResolvedPrice,
} from '../invoices/invoices-types';
import { type PickAsked, pickParams } from '../shared/form/pick-api';
import { apiRangeKey } from '../shared/list/list-filters';
import type { ListPage } from '../shared/list/list-types';
import { trackingOf } from '../products/products-types';
import {
  QUOTE_STATUSES,
  type DepositShare,
  type QuoteAnswer,
  type QuoteAttachment,
  type QuoteInput,
  type QuoteRow,
  type QuoteSearch,
  type QuotesError,
  type QuoteStatusCounts,
} from './quotes-types';

/** Thrown when the API refuses; carries the code the UI translates. */
export class QuotesRefused extends Error {
  constructor(readonly code: QuotesError) {
    super(code);
  }
}

/** The HTTP edge of the quotes feature: the only code here that knows endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class QuotesApi {
  private readonly http = inject(HttpClient);
  private readonly invoices = inject(InvoicesApi);

  /** What the quote form offers, read with quote.read alone: the currency, establishments, units and taxes. */
  async options(companyId: string): Promise<InvoiceOptions> {
    return this.guard(async () =>
      toOptions(
        await firstValueFrom(
          this.http.get<QuoteOptionsQuoteOptionsRead>(`${companyPath(companyId)}/quote-options`),
        ),
      ),
    );
  }

  async pickCustomers(companyId: string, asked: PickAsked): Promise<CustomerOption[]> {
    return this.guard(async () => {
      const rows = await firstValueFrom(
        this.http.get<QuoteCustomerPickQuoteCustomerPickRead[]>(
          `${companyPath(companyId)}/quote-options/customers`,
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

  /** None while the company has products off: every line is then written by hand. */
  async pickProducts(companyId: string, asked: PickAsked): Promise<ProductOption[]> {
    return this.guard(async () => {
      const rows = await firstValueFrom(
        this.http.get<QuoteProductPickQuoteProductPickRead[]>(
          `${companyPath(companyId)}/quote-options/products`,
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

  /** The price a line starts at for that customer and quantity: the same answer an invoice line reads. */
  async productPrice(
    companyId: string,
    productId: string,
    customerId: string | null,
    quantity: string,
  ): Promise<ResolvedPrice | null> {
    return this.invoices.productPrice(companyId, productId, customerId, quantity);
  }

  /** One page of the company's quotes, searched, narrowed and sorted by the API. */
  async quotes(companyId: string, search: QuoteSearch): Promise<ListPage<QuoteRow>> {
    return this.guard(async () => {
      const page = await firstValueFrom(
        this.http.get<ApiCompaniesCompanyIdquotesGetCollectionResponse>(quotePath(companyId), {
          headers: { Accept: 'application/ld+json' },
          params: toSearchParams(search),
        }),
      );
      if (page.totalItems === undefined)
        throw new Error('A page of quotes came without its total.');
      return { rows: page.member.map(toQuote), total: page.totalItems };
    });
  }

  /** What each status chip would list under the list's words, customers and days. */
  async statusCounts(companyId: string, search: QuoteSearch): Promise<QuoteStatusCounts> {
    return this.guard(async () => {
      const counts = await firstValueFrom(
        this.http.get<QuoteStatusCountsQuoteStatusCountsRead>(
          `${companyPath(companyId)}/quote-status-counts`,
          { params: toCountParams(search) },
        ),
      );
      if (counts.all === undefined || counts.statuses === undefined)
        throw new Error('Status counts came without their figures.');
      return { all: counts.all, statuses: { ...counts.statuses } };
    });
  }

  async quote(companyId: string, id: string): Promise<QuoteRow> {
    return this.guard(async () =>
      toQuote(await firstValueFrom(this.http.get<QuoteQuoteRead>(quotePath(companyId, id)))),
    );
  }

  /** 422 naming what the API refused. */
  async create(companyId: string, input: QuoteInput): Promise<QuoteRow> {
    return this.guard(async () =>
      toQuote(
        await firstValueFrom(this.http.post<QuoteQuoteRead>(quotePath(companyId), toBody(input))),
      ),
    );
  }

  /** 409 once the quote is no longer a draft. */
  async revise(companyId: string, id: string, input: QuoteInput): Promise<QuoteRow> {
    return this.guard(async () =>
      toQuote(
        await firstValueFrom(
          this.http.put<QuoteQuoteRead>(quotePath(companyId, id), toBody(input)),
        ),
      ),
    );
  }

  /** What the quote would come to with what is typed, kept nowhere; quiet, and a refusal is thrown. */
  async preview(companyId: string, id: string | null, input: QuoteInput): Promise<PreviewBody> {
    const path =
      id === null ? `${quotePath(companyId)}/preview` : `${quotePath(companyId, id)}/preview`;
    return firstValueFrom(
      this.http.post<QuoteDocumentPreviewDocumentPreviewValidationQuoteWrite>(path, toBody(input), {
        context: new HttpContext().set(SILENT, true),
      }),
    );
  }

  /** « Marquer envoyé »: numbers the draft and fixes its validity; 409 when it is not one, 422 without lines. */
  async send(companyId: string, id: string): Promise<QuoteRow> {
    return this.step(companyId, id, 'send', null);
  }

  /** The customer's yes, on a day from the issue day to the company's today; empty is today. */
  async accept(companyId: string, id: string, answer: QuoteAnswer): Promise<QuoteRow> {
    return this.step(companyId, id, 'accept', { answeredOn: day(answer.answeredOn) });
  }

  /** The customer's no, and why when they said; the reason never enters the audit trail. */
  async refuse(companyId: string, id: string, answer: QuoteAnswer): Promise<QuoteRow> {
    const reason = answer.refusalReason?.trim() ?? '';
    const body: QuoteRefuseBody = {
      answeredOn: day(answer.answeredOn),
      refusalReason: reason === '' ? null : reason,
    };
    return this.step(companyId, id, 'refuse', body);
  }

  /** Only a draft is cancelled. */
  async cancel(companyId: string, id: string): Promise<QuoteRow> {
    return this.step(companyId, id, 'cancel', null);
  }

  /**
   * Draws a draft deposit invoice from the accepted quote, which the answer lists last among its deposits; 422 when
   * the share is refused or goes beyond what the quote's deposits leave.
   */
  async deposit(companyId: string, id: string, share: DepositShare): Promise<QuoteRow> {
    return this.guard(
      async () =>
        toQuote(
          await firstValueFrom(
            this.http.post<QuoteQuoteRead>(`${quotePath(companyId, id)}/deposit-invoices`, {
              depositPercentage: 'percentage' in share ? share.percentage : null,
              depositAmount: 'amount' in share ? share.amount : null,
            }),
          ),
        ),
      'deposit_refused',
    );
  }

  /** Drafts the accepted quote wholly into a new invoice, which the answer names; 409 when it already stands. */
  async invoice(companyId: string, id: string): Promise<QuoteRow> {
    return this.step(companyId, id, 'invoice', null);
  }

  /** Where the quote's PDF is downloaded from, with the session the browser already has. */
  pdfUrl(companyId: string, id: string): string {
    return `${quotePath(companyId, id)}/pdf`;
  }

  async attachments(companyId: string, id: string): Promise<QuoteAttachment[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<QuoteAttachmentQuoteAttachmentRead[]>(attachmentsPath(companyId, id)),
        )
      ).map(toAttachment),
    );
  }

  /** A multipart part named `file`, such as the signed copy; 422 for a file the API does not keep. */
  async attach(companyId: string, id: string, file: File): Promise<QuoteAttachment> {
    const body = new FormData();
    body.append('file', file, file.name);
    return this.guard(
      async () =>
        toAttachment(
          await firstValueFrom(
            this.http.post<QuoteAttachmentQuoteAttachmentRead>(
              attachmentsPath(companyId, id),
              body,
            ),
          ),
        ),
      'file_refused',
    );
  }

  async detach(companyId: string, id: string, attachmentId: string): Promise<void> {
    await this.guard(async () =>
      firstValueFrom(
        this.http.delete(`${attachmentsPath(companyId, id)}/${encodeURIComponent(attachmentId)}`),
      ),
    );
  }

  /** Where the browser opens a file: a same-origin address the session cookie reaches. */
  attachmentUrl(companyId: string, id: string, attachmentId: string): string {
    return `${attachmentsPath(companyId, id)}/${encodeURIComponent(attachmentId)}/content`;
  }

  private async step(
    companyId: string,
    id: string,
    action: 'send' | 'accept' | 'refuse' | 'cancel' | 'invoice',
    body: object | null,
  ): Promise<QuoteRow> {
    return this.guard(async () =>
      toQuote(
        await firstValueFrom(
          this.http.post<QuoteQuoteRead>(`${quotePath(companyId, id)}/${action}`, body),
        ),
      ),
    );
  }

  /** `invalid` names what a 422 means here: a file refused when one was sent, else a field. */
  private async guard<T>(call: () => Promise<T>, invalid?: QuotesError): Promise<T> {
    try {
      return await call();
    } catch (error) {
      throw new QuotesRefused(codeOf(error, invalid));
    }
  }
}

function codeOf(error: unknown, invalid: QuotesError | undefined): QuotesError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) return 'network';
  switch (error.status) {
    case 404:
      return 'not_found';
    case 409:
      return 'conflict';
    case 413:
      return 'file_refused';
    default:
      // « Facturer » while a deposit drawn from the quote is still a draft.
      if (refusedField(error) === 'deposits') return 'deposit_pending';
      if (invalid !== undefined) return invalid;
      // A customer deactivated since the quote was started, named by the field the API refused.
      return refusedField(error) === 'customerId' ? 'customer_unavailable' : 'invalid';
  }
}

/** An empty day is the API's own today, sent as no day at all. */
const day = (value: string): string | null => (value.trim() === '' ? null : value.trim());

const companyPath = (companyId: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}`;

const quotePath = (companyId: string, id?: string): string =>
  `${companyPath(companyId)}/quotes${id === undefined ? '' : `/${encodeURIComponent(id)}`}`;

const attachmentsPath = (companyId: string, id: string): string =>
  `${quotePath(companyId, id)}/attachments`;

/** Only what the search asks for: an absent parameter is the API's own default, never an empty one. */
function toSearchParams(search: QuoteSearch): HttpParams {
  let params = new HttpParams().set('page', search.page).set('itemsPerPage', search.itemsPerPage);
  if (search.q.trim() !== '') params = params.set('q', search.q.trim());
  for (const status of search.status) params = params.append('status[]', status);
  params = narrowing(params, search);
  if (search.order !== null)
    params = params.set(`order[${search.order.key}]`, search.order.direction);
  return params;
}

function narrowing(params: HttpParams, search: QuoteSearch): HttpParams {
  let next = params;
  for (const id of search.customerIds) next = next.append('customerId[]', id);
  for (const [key, value] of Object.entries(search.intervals)) {
    next = next.set(apiRangeKey(key), value);
  }
  return next;
}

/** The chips narrow by status themselves, and a count has no page or order. */
function toCountParams(search: QuoteSearch): HttpParams {
  let params = new HttpParams();
  if (search.q.trim() !== '') params = params.set('q', search.q.trim());
  return narrowing(params, search);
}

function toQuote(raw: QuoteQuoteRead | QuoteJsonldQuoteRead): QuoteRow {
  return {
    id: raw.id ?? '',
    number: raw.number ?? null,
    status: QUOTE_STATUSES.find((status) => status === raw.status) ?? 'draft',
    expired: raw.expired ?? false,
    customerId: raw.customerId ?? '',
    recordedCustomerName: raw.customerSnapshot?.name ?? null,
    customerName: raw.customerName ?? '',
    establishmentId: raw.establishmentId ?? null,
    issueDate: raw.issueDate ?? null,
    validUntil: raw.validUntil ?? null,
    answeredOn: raw.answeredOn ?? null,
    refusalReason: raw.refusalReason ?? null,
    invoiceId: raw.invoiceId ?? null,
    deposits: (raw.deposits ?? []).map(({ invoiceId, number, status, total }) => ({
      invoiceId,
      number: number ?? null,
      status,
      total,
    })),
    attachmentCount: raw.attachmentCount ?? 0,
    customerReference: raw.customerReference ?? null,
    notesPrinted: raw.notesPrinted ?? null,
    notesInternal: raw.notesInternal ?? null,
    discountAmount: raw.discountAmount ?? null,
    lines: (raw.lines ?? []).map((line) => ({
      productId: line.productId ?? null,
      description: line.description ?? '',
      quantity: line.quantity,
      unitId: line.unitId ?? '',
      unitPriceNet: line.unitPriceNet ?? '',
      discountRate: line.discountRate ?? null,
      discountAmount: line.discountAmount ?? null,
      taxComponentIds: [...(line.taxComponentIds ?? [])],
      sourceDeliveryNoteLineId: null,
      sourceLeft: null,
      productReference: line.productReference ?? null,
      productName: line.productName ?? null,
      productTracking: null,
      lotCode: null,
      returned: false,
      deductsInvoiceId: null,
      net: line.net ?? '',
    })),
    subtotalNet: raw.subtotalNet ?? '0',
    documentDiscount: raw.documentDiscount ?? '0',
    savings: raw.savings ?? '0',
    taxes: (raw.taxes ?? []).map(({ code, rate, base, amount }) => ({ code, rate, base, amount })),
    totalTax: raw.totalTax ?? '0',
    total: raw.total ?? '0',
  };
}

function toBody(input: QuoteInput): QuoteWriteBody {
  return {
    ...input,
    lines: input.lines.map((line) => ({ ...line, taxComponentIds: [...line.taxComponentIds] })),
  };
}

function toOptions(raw: QuoteOptionsQuoteOptionsRead): InvoiceOptions {
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
    // A quote states no category of operations: the invoice it becomes does, when it is issued.
    operationCategory: false,
  };
}

function toAttachment(raw: QuoteAttachmentQuoteAttachmentRead): QuoteAttachment {
  return {
    id: raw.id ?? '',
    name: raw.name ?? '',
    mime: raw.mime ?? '',
    size: raw.size ?? 0,
    createdAt: raw.createdAt ?? '',
  };
}
