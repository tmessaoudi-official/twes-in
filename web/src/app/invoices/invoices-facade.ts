// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { InvoicesApi, InvoicesRefused } from './invoices-api';
import type { PickAsked } from '../shared/form/pick-api';
import type {
  CustomerOption,
  InvoiceInput,
  InvoiceOptions,
  InvoiceRow,
  InvoiceSearch,
  InvoicesError,
  InvoiceSummary,
  PaymentInput,
  ProductOption,
} from './invoices-types';

/** The invoices and credit notes of the company being worked in, the document open on screen and what its form offers. */
@Injectable({ providedIn: 'root' })
export class InvoicesFacade {
  private readonly api = inject(InvoicesApi);
  private readonly invoicesSignal = signal<readonly InvoiceRow[]>([]);
  private readonly optionsSignal = signal<InvoiceOptions | null>(null);
  private readonly invoiceSignal = signal<InvoiceRow | null>(null);
  private readonly summarySignal = signal<InvoiceSummary | null>(null);
  private readonly totalSignal = signal(0);
  private pageRequest = 0;
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<InvoicesError | null>(null);

  readonly invoices = this.invoicesSignal.asReadonly();
  readonly options = this.optionsSignal.asReadonly();
  /** How many documents the last search found in all, the page shown being one part of them. */
  readonly total = this.totalSignal.asReadonly();
  /** The document open on screen, as the API last answered with it; null while a new one is filled in. */
  readonly invoice = this.invoiceSignal.asReadonly();
  /** The home page's figures; null until read, and again when the read is refused. */
  readonly summary = this.summarySignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  /** What the list screen needs besides its page: the options that name its drafts' customers. */
  async loadListContext(companyId: string): Promise<void> {
    await this.read(async () => this.optionsSignal.set(await this.api.options(companyId)));
  }

  /**
   * One page of the documents the search finds. Only the latest search's answer is shown: typing sends one search per
   * keystroke and they need not come back in order.
   */
  async loadPage(companyId: string, search: InvoiceSearch): Promise<void> {
    const request = ++this.pageRequest;
    await this.read(async () => {
      const page = await this.api.invoices(companyId, search);
      if (request !== this.pageRequest) return;
      this.invoicesSignal.set(page.rows);
      this.totalSignal.set(page.total);
    });
  }

  async loadSummary(companyId: string): Promise<void> {
    this.summarySignal.set(null);
    await this.read(async () => this.summarySignal.set(await this.api.summary(companyId)));
  }

  /** What the document screen needs: the form's options, and the document unless it is new. */
  async loadInvoice(companyId: string, id: string | null): Promise<void> {
    await this.read(async () => {
      const [options, invoice] = await Promise.all([
        this.api.options(companyId),
        id === null ? Promise.resolve(null) : this.api.invoice(companyId, id),
      ]);
      this.optionsSignal.set(options);
      this.invoiceSignal.set(invoice);
    });
  }

  /**
   * The few customers or products a person means while typing, and — by id — exactly the records an open document
   * names, still offered or not. A search that fails answers nothing and says so in `error`, rather than reading as
   * "nothing found": what the picker could not ask for is not the same as what does not exist.
   */
  async pickCustomers(companyId: string, asked: PickAsked): Promise<CustomerOption[]> {
    return this.pick(() => this.api.pickCustomers(companyId, asked));
  }

  async pickProducts(companyId: string, asked: PickAsked): Promise<ProductOption[]> {
    return this.pick(() => this.api.pickProducts(companyId, asked));
  }

  /** The draft as the API kept it, or null with the reason in `error`. */
  async create(companyId: string, input: InvoiceInput): Promise<InvoiceRow | null> {
    return this.step(() => this.api.create(companyId, input));
  }

  async revise(companyId: string, id: string, input: InvoiceInput): Promise<InvoiceRow | null> {
    return this.step(() => this.api.revise(companyId, id, input));
  }

  /** Saves what is on screen, then issues it: a document is never issued with content other than the one shown. */
  async reviseAndIssue(
    companyId: string,
    id: string,
    input: InvoiceInput,
  ): Promise<InvoiceRow | null> {
    return this.step(async () => {
      this.invoiceSignal.set(await this.api.revise(companyId, id, input));
      return this.api.issue(companyId, id);
    });
  }

  async cancel(companyId: string, id: string): Promise<InvoiceRow | null> {
    return this.step(() => this.api.cancel(companyId, id));
  }

  /** The credit note drafted for an issued invoice, stating why, which becomes the document on screen. */
  async creditNote(companyId: string, id: string, reason: string): Promise<InvoiceRow | null> {
    return this.step(() => this.api.creditNote(companyId, id, reason));
  }

  /** A copy of the document as a new draft, which becomes the document on screen. */
  async duplicate(companyId: string, id: string): Promise<InvoiceRow | null> {
    return this.step(() => this.api.duplicate(companyId, id));
  }

  /** True once recorded, with the invoice read again so its amount due and status are the API's. */
  async recordPayment(companyId: string, id: string, payment: PaymentInput): Promise<boolean> {
    return this.paymentStep(companyId, id, () => this.api.recordPayment(companyId, id, payment));
  }

  async deletePayment(companyId: string, id: string, paymentId: string): Promise<boolean> {
    return this.paymentStep(companyId, id, () => this.api.deletePayment(companyId, id, paymentId));
  }

  pdfUrl(companyId: string, id: string): string {
    return this.api.pdfUrl(companyId, id);
  }

  clearError(): void {
    this.errorSignal.set(null);
  }

  /** A picker's own call: it never marks the screen busy, because a person is typing while it runs. */
  private async pick<T>(call: () => Promise<T[]>): Promise<T[]> {
    try {
      return await call();
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return [];
    }
  }

  private async read(load: () => Promise<void>): Promise<void> {
    this.busySignal.set(true);
    try {
      await load();
      this.errorSignal.set(null);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      this.busySignal.set(false);
    }
  }

  private async step(call: () => Promise<InvoiceRow>): Promise<InvoiceRow | null> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      const invoice = await call();
      this.invoiceSignal.set(invoice);
      return invoice;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return null;
    } finally {
      this.busySignal.set(false);
    }
  }

  private async paymentStep(
    companyId: string,
    id: string,
    call: () => Promise<void>,
  ): Promise<boolean> {
    const invoice = await this.step(async () => {
      await call();
      return this.api.invoice(companyId, id);
    });
    return invoice !== null;
  }
}

function codeOf(error: unknown): InvoicesError {
  return error instanceof InvoicesRefused ? error.code : 'network';
}
