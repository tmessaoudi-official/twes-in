// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  ApiCompaniesCompanyIdinvoicesGetCollectionResponse,
  InvoiceInvoiceRead,
  InvoiceJsonldInvoiceRead,
  InvoiceInvoiceWrite,
  InvoiceCustomerPickInvoiceCustomerPickRead,
  InvoiceOptionsInvoiceOptionsRead,
  InvoiceProductPickInvoiceProductPickRead,
  InvoiceSummaryInvoiceSummaryRead,
  PaymentPaymentWrite,
} from '../api/types.gen';
import type { ListPage } from '../shared/list/list-types';
import { type PickAsked, pickParams } from '../shared/form/pick-api';
import {
  AGING_BUCKETS,
  type AgingAmount,
  INVOICE_STATUSES,
  type InvoiceInput,
  type InvoiceOptions,
  type InvoiceRow,
  type InvoiceSearch,
  type InvoicesError,
  type InvoiceSummary,
  PAYMENT_METHODS,
  type CustomerOption,
  type ProductOption,
  type PaymentInput,
} from './invoices-types';

/** Thrown when the API refuses; carries the code the UI translates. */
export class InvoicesRefused extends Error {
  constructor(readonly code: InvoicesError) {
    super(code);
  }
}

/** The HTTP edge of the invoices feature: the only code here that knows endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class InvoicesApi {
  private readonly http = inject(HttpClient);

  /** What the invoice form offers: the currency, and the establishments, customers, products, units and taxes. */
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
      }));
    });
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

  /** 409 once the document is no longer a draft. */
  async revise(companyId: string, id: string, input: InvoiceInput): Promise<InvoiceRow> {
    return this.guard(async () =>
      toInvoice(
        await firstValueFrom(
          this.http.put<InvoiceInvoiceRead>(invoicePath(companyId, id), toBody(input)),
        ),
      ),
    );
  }

  /** Numbers a draft and fixes what it says; 409 when its establishment cannot number it, 422 without lines. */
  async issue(companyId: string, id: string): Promise<InvoiceRow> {
    return this.step(companyId, id, 'issue');
  }

  /** Cancels a draft; an issued document is never cancelled (409). */
  async cancel(companyId: string, id: string): Promise<InvoiceRow> {
    return this.step(companyId, id, 'cancel');
  }

  /** Drafts a credit note for an issued invoice from its lines; the answer is the credit note. */
  async creditNote(companyId: string, id: string): Promise<InvoiceRow> {
    return this.step(companyId, id, 'credit-notes');
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

  async deletePayment(companyId: string, id: string, paymentId: string): Promise<void> {
    await this.guard(() =>
      firstValueFrom(
        this.http.delete(`${invoicePath(companyId, id)}/payments/${encodeURIComponent(paymentId)}`),
      ),
    );
  }

  /** Where a document's PDF is downloaded from, with the session the browser already has. */
  pdfUrl(companyId: string, id: string): string {
    return `${invoicePath(companyId, id)}/pdf`;
  }

  private async step(
    companyId: string,
    id: string,
    action: 'issue' | 'cancel' | 'credit-notes' | 'duplicate',
  ): Promise<InvoiceRow> {
    return this.guard(async () =>
      toInvoice(
        await firstValueFrom(
          this.http.post<InvoiceInvoiceRead>(`${invoicePath(companyId, id)}/${action}`, null),
        ),
      ),
    );
  }

  private async guard<T>(call: () => Promise<T>): Promise<T> {
    try {
      return await call();
    } catch (error) {
      throw new InvoicesRefused(codeOf(error));
    }
  }
}

function codeOf(error: unknown): InvoicesError {
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

const invoicePath = (companyId: string, id?: string): string =>
  `${companyPath(companyId)}/invoices${id === undefined ? '' : `/${encodeURIComponent(id)}`}`;

const ids = (values: readonly (string | null | undefined)[] | null | undefined): string[] =>
  (values ?? []).filter((id): id is string => typeof id === 'string');

function toSearchParams(search: InvoiceSearch): HttpParams {
  let params = new HttpParams().set('page', search.page).set('itemsPerPage', search.itemsPerPage);
  if (search.q.trim() !== '') params = params.set('q', search.q.trim());
  if (search.status !== null) params = params.set('status', search.status);
  if (search.documentType !== null) params = params.set('documentType', search.documentType);
  if (search.customerId !== null) params = params.set('customerId', search.customerId);
  if (search.order !== null)
    params = params.set(`order[${search.order.key}]`, search.order.direction);
  return params;
}

function toInvoice(raw: InvoiceInvoiceRead | InvoiceJsonldInvoiceRead): InvoiceRow {
  return {
    id: raw.id ?? '',
    type: raw.type === 'credit_note' ? 'credit_note' : 'invoice',
    correctsInvoiceId: raw.correctsInvoiceId ?? null,
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
    discountAmount: raw.discountAmount ?? null,
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
      taxComponentIds: ids(line.taxComponentIds),
      sourceDeliveryNoteLineId: line.sourceDeliveryNoteLineId ?? null,
      productReference: line.productReference ?? null,
      productName: line.productName ?? null,
      net: line.net ?? '',
    })),
    subtotalNet: raw.subtotalNet ?? '0',
    documentDiscount: raw.documentDiscount ?? '0',
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
    vat: (raw.vat ?? []).map(({ code, rate, amount }) => ({ code, rate, amount })),
    vatTotal: raw.vatTotal ?? '0',
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
  };
}
