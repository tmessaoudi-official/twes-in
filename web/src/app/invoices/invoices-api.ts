// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  InvoiceInvoiceRead,
  InvoiceInvoiceWrite,
  InvoiceOptionsInvoiceOptionsRead,
  PaymentPaymentWrite,
} from '../api/types.gen';
import {
  INVOICE_STATUSES,
  type InvoiceInput,
  type InvoiceOptions,
  type InvoiceRow,
  type InvoicesError,
  PAYMENT_METHODS,
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

  /** The company's invoices and credit notes. */
  async invoices(companyId: string): Promise<InvoiceRow[]> {
    return this.guard(async () =>
      (await firstValueFrom(this.http.get<InvoiceInvoiceRead[]>(invoicePath(companyId)))).map(
        toInvoice,
      ),
    );
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
    action: 'issue' | 'cancel' | 'credit-notes',
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

function toInvoice(raw: InvoiceInvoiceRead): InvoiceRow {
  return {
    id: raw.id ?? '',
    type: raw.type === 'credit_note' ? 'credit_note' : 'invoice',
    correctsInvoiceId: raw.correctsInvoiceId ?? null,
    number: raw.number ?? null,
    status: INVOICE_STATUSES.find((status) => status === raw.status) ?? 'draft',
    customerId: raw.customerId ?? '',
    customerName: raw.customerSnapshot?.name ?? null,
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
    customers: (raw.customers ?? []).map((customer) => ({
      id: customer.id,
      number: customer.number,
      name: customer.name,
      excludedFamilies: [...customer.excludedFamilies],
      defaultDiscountRate: customer.defaultDiscountRate,
      defaultTaxComponentIds: [...customer.defaultTaxComponentIds],
    })),
    products: (raw.products ?? []).map((product) => ({
      id: product.id,
      reference: product.reference,
      name: product.name,
      unitId: product.unitId,
      unitPriceNet: product.unitPriceNet,
      defaultTaxComponentIds: [...product.defaultTaxComponentIds],
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
