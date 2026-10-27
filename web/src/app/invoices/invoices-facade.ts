// SPDX-License-Identifier: AGPL-3.0-or-later

import { type FormAnswer, LatestRequest, requestState } from '../shared/feedback/request-state';
import type { ExportFormat } from '../shared/list/export-address';
import { inject, Injectable } from '@angular/core';
import { InvoicesApi, InvoicesRefused } from './invoices-api';
import type { PickAsked } from '../shared/form/pick-api';
import type {
  BulkAction,
  BulkCheck,
  BulkPaid,
  BulkPayment,
  BulkPdfFormat,
  CreditExcessTo,
  CustomerOption,
  FacturXAnswer,
  FacturXFormat,
  InvoiceInput,
  InvoiceOptions,
  InvoiceRow,
  InvoiceSearch,
  InvoicesError,
  InvoiceStatusCounts,
  InvoiceSummary,
  IssuePreview,
  OverpaymentInput,
  PaymentInput,
  ProductOption,
} from './invoices-types';
import type { PreviewBody } from '../shared/documents/document-figures';
import { sessionSignal } from '../shared/session/session-data';
import type { FieldRefusal } from '../shared/form/field-refusals';
import type { ReceiverChoice, ReceiverContact } from '../shared/receivers/receiver';

/** The invoices and credit notes of the company being worked in, the document open on screen and what its form offers. */
@Injectable({ providedIn: 'root' })
export class InvoicesFacade {
  private readonly api = inject(InvoicesApi);
  private readonly invoicesSignal = sessionSignal<readonly InvoiceRow[]>([]);
  private readonly optionsSignal = sessionSignal<InvoiceOptions | null>(null);
  private readonly invoiceSignal = sessionSignal<InvoiceRow | null>(null);
  private readonly summarySignal = sessionSignal<InvoiceSummary | null>(null);
  private readonly totalSignal = sessionSignal(0);
  private readonly statusCountsSignal = sessionSignal<InvoiceStatusCounts | null>(null);
  private readonly refusalsSignal = sessionSignal<readonly FieldRefusal[]>([]);
  private readonly pages = new LatestRequest();
  private readonly counts = new LatestRequest();
  private readonly state = requestState(codeOf);

  readonly invoices = this.invoicesSignal.asReadonly();
  readonly options = this.optionsSignal.asReadonly();
  /** How many documents the last search found in all, the page shown being one part of them. */
  readonly total = this.totalSignal.asReadonly();
  /** What each status chip of the list would show; null until read, and kept while a new count is on its way. */
  readonly statusCounts = this.statusCountsSignal.asReadonly();
  /** The document open on screen, as the API last answered with it; null while a new one is filled in. */
  readonly invoice = this.invoiceSignal.asReadonly();
  /** The home page's figures; null until read, and again when the read is refused. */
  readonly summary = this.summarySignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;
  /** Each field the last step was refused on, with why; empty after a step the API took. */
  readonly refusals = this.refusalsSignal.asReadonly();

  /** What the list screen needs besides its page: the options that name its drafts' customers. */
  async loadListContext(companyId: string): Promise<void> {
    await this.read(async () => this.optionsSignal.set(await this.api.options(companyId)));
  }

  /**
   * One page of the documents the search finds. Only the latest search's answer is shown: typing sends one search per
   * keystroke and they need not come back in order.
   */
  async loadPage(companyId: string, search: InvoiceSearch): Promise<void> {
    const current = this.pages.next();
    await this.read(async () => {
      const page = await this.api.invoices(companyId, search);
      if (!current()) return;
      this.invoicesSignal.set(page.rows);
      this.totalSignal.set(page.total);
    });
  }

  /** The chips' counts for the search, the latest search's answer only, as for its page. */
  async loadStatusCounts(companyId: string, search: InvoiceSearch): Promise<void> {
    const current = this.counts.next();
    await this.read(async () => {
      const counts = await this.api.statusCounts(companyId, search);
      if (current()) this.statusCountsSignal.set(counts);
    });
  }

  async loadSummary(companyId: string): Promise<void> {
    this.summarySignal.set(null);
    await this.read(async () => this.summarySignal.set(await this.api.summary(companyId)));
  }

  /** The number a draft would carry if issued now, or null when it cannot be said; never an error on screen. */
  async nextNumber(companyId: string, id: string): Promise<IssuePreview | null> {
    try {
      return await this.api.nextNumber(companyId, id);
    } catch {
      return null;
    }
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
   * One document for the list's sheet (docs/SPEC.md § 7, 2026-09-26): it leaves the document open on screen, the
   * screen's busy state and its error alone, since the list stays usable beside it; null when it cannot be read.
   */
  async peek(companyId: string, id: string): Promise<InvoiceRow | null> {
    try {
      return await this.api.invoice(companyId, id);
    } catch {
      return null;
    }
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

  /** The customer's contacts « Reçu par » offers; none when they cannot be read, which the screen says. */
  async receivers(companyId: string, customerId: string): Promise<ReceiverContact[]> {
    return this.pick(() => this.api.receivers(companyId, customerId));
  }

  /** What the product sells at for that customer and quantity; null where the price cannot be read. */
  async productPrice(
    companyId: string,
    productId: string,
    customerId: string | null,
    quantity: string,
  ) {
    return this.api.productPrice(companyId, productId, customerId, quantity);
  }

  /** The draft as the API kept it, or null with the reason in `error`. */
  async create(companyId: string, input: InvoiceInput): Promise<InvoiceRow | null> {
    return this.step(() => this.api.create(companyId, input));
  }

  async revise(
    companyId: string,
    id: string,
    input: InvoiceInput,
    version: number | null,
  ): Promise<InvoiceRow | null> {
    return this.step(() => this.api.revise(companyId, id, input, version));
  }

  /**
   * What is typed would come to, or null when the API refuses it as it stands: a draft being typed is often not one
   * yet, so a refusal here is no error to report, and the screen's error is left alone.
   */
  async preview(
    companyId: string,
    id: string | null,
    input: InvoiceInput,
  ): Promise<PreviewBody | null> {
    try {
      return await this.api.preview(companyId, id, input);
    } catch {
      return null;
    }
  }

  /**
   * Saves what is on screen, then issues it: a document is never issued with content other than the one shown.
   * `revised` is told once the save is kept, whatever issuing then answers, so the form stands on what was saved.
   */
  async reviseAndIssue(
    companyId: string,
    id: string,
    input: InvoiceInput,
    version: number | null,
    revised: () => void,
    excessTo?: CreditExcessTo,
  ): Promise<InvoiceRow | null> {
    return this.step(async () => {
      this.invoiceSignal.set(await this.api.revise(companyId, id, input, version));
      revised();
      return this.api.issue(companyId, id, excessTo);
    });
  }

  async cancel(companyId: string, id: string): Promise<InvoiceRow | null> {
    return this.step(() => this.api.cancel(companyId, id));
  }

  /**
   * The credit note drafted for an issued invoice, stating why, which becomes the document on screen; asked in a
   * dialog, which says a refusal under its field.
   */
  async creditNote(companyId: string, id: string, reason: string): Promise<FormAnswer<InvoiceRow>> {
    return this.inDialog(() => this.api.creditNote(companyId, id, reason));
  }

  /**
   * Who received the goods an issued invoice handed over itself, put right; asked in a dialog, which says a refusal
   * under its field, and answered as the document on screen.
   */
  async correctReceiver(
    companyId: string,
    id: string,
    receivedBy: ReceiverChoice | null,
  ): Promise<FormAnswer<InvoiceRow>> {
    return this.inDialog(() => this.api.correctReceiver(companyId, id, receivedBy));
  }

  /**
   * « Livrer » or « Livrer une partie »: a draft delivery note of the issued invoice's goods, every line for what is left
   * of it or only the quantities given by invoice line id; the note's id, or null with the reason in `error`. The page
   * leaves for the note, which is where it is checked and validated.
   */
  async deliver(
    companyId: string,
    id: string,
    quantities: Readonly<Record<string, string>> | null,
  ): Promise<string | null> {
    return this.state.writeAnswer(() => this.api.deliver(companyId, id, quantities));
  }

  /** « Livrer une partie », asked in a dialog, which says a line's refused quantity on its line. */
  async deliverPart(
    companyId: string,
    id: string,
    quantities: Readonly<Record<string, string>>,
  ): Promise<FormAnswer<string>> {
    return this.state.writeOrRefuse(
      () => this.api.deliver(companyId, id, quantities),
      (error) => (error instanceof InvoicesRefused ? error.refusals : []),
    );
  }

  /** A copy of the document as a new draft, which becomes the document on screen. */
  async duplicate(companyId: string, id: string): Promise<InvoiceRow | null> {
    return this.step(() => this.api.duplicate(companyId, id));
  }

  /**
   * The payment recorded, with the invoice read again so its amount due and status are the API's; asked in a dialog,
   * which says a refusal under its field.
   */
  async recordPayment(
    companyId: string,
    id: string,
    payment: PaymentInput,
  ): Promise<FormAnswer<InvoiceRow>> {
    return this.dialogPaymentStep(companyId, id, () =>
      this.api.recordPayment(companyId, id, payment),
    );
  }

  /** What the customer has to their credit; zero when it cannot be read, since it only decides whether to offer applying it. */
  async customerCredit(companyId: string, customerId: string): Promise<string> {
    try {
      return await this.api.customerCredit(companyId, customerId);
    } catch {
      return '0';
    }
  }

  /** True once applied, with the invoice read again. */
  async applyCredit(companyId: string, id: string): Promise<boolean> {
    return this.paymentStep(companyId, id, () => this.api.applyCredit(companyId, id));
  }

  /** What the customer paid beyond the invoice, kept to their credit; asked in a dialog, as a payment is. */
  async overpay(
    companyId: string,
    id: string,
    overpayment: OverpaymentInput,
  ): Promise<FormAnswer<InvoiceRow>> {
    return this.dialogPaymentStep(companyId, id, () =>
      this.api.overpay(companyId, id, overpayment),
    );
  }

  async deletePayment(companyId: string, id: string, paymentId: string): Promise<boolean> {
    return this.paymentStep(companyId, id, () => this.api.deletePayment(companyId, id, paymentId));
  }

  exportUrl(companyId: string, search: InvoiceSearch, format: ExportFormat): string {
    return this.api.exportUrl(companyId, search, format);
  }

  selectedExportUrl(companyId: string, ids: readonly string[], format: ExportFormat): string {
    return this.api.selectedExportUrl(companyId, ids, format);
  }

  selectedPdfUrl(companyId: string, ids: readonly string[], format: BulkPdfFormat): string {
    return this.api.selectedPdfUrl(companyId, ids, format);
  }

  /** What a bulk action would do with each selected invoice; throws when the API cannot say. */
  bulkCheck(
    companyId: string,
    action: BulkAction,
    ids: readonly string[],
    date: string | null = null,
  ): Promise<BulkCheck> {
    return this.api.bulkCheck(companyId, action, ids, date);
  }

  /** Every payment of a selection or none; throws `InvoicesRefused` with `conflict` once an invoice changed since its check. */
  bulkPay(companyId: string, payment: BulkPayment): Promise<BulkPaid> {
    return this.api.bulkPay(companyId, payment);
  }

  pdfUrl(companyId: string, id: string): string {
    return this.api.pdfUrl(companyId, id);
  }

  pdfCopyUrl(companyId: string, id: string, kind: 'duplicate' | 'current'): string {
    return this.api.pdfCopyUrl(companyId, id, kind);
  }

  /** An issued document's Factur-X, or why it is not written; null when the call failed, with the error said. */
  async facturX(
    companyId: string,
    id: string,
    format: FacturXFormat,
  ): Promise<FacturXAnswer | null> {
    return this.state.writeAnswer(() => this.api.facturX(companyId, id, format));
  }

  clearError(): void {
    this.state.clearError();
  }

  /** A picker's own call: it never marks the screen busy, because a person is typing while it runs. */
  private async pick<T>(call: () => Promise<T[]>): Promise<T[]> {
    try {
      return await call();
    } catch (error) {
      this.state.fail(error);
      return [];
    }
  }

  private async read(load: () => Promise<void>): Promise<void> {
    await this.state.read(load);
  }

  private async step(call: () => Promise<InvoiceRow>): Promise<InvoiceRow | null> {
    this.refusalsSignal.set([]);
    return this.state.writeAnswer(
      async () => {
        const invoice = await call();
        this.invoiceSignal.set(invoice);
        return invoice;
      },
      {
        failed: (error) =>
          this.refusalsSignal.set(error instanceof InvoicesRefused ? error.refusals : []),
      },
    );
  }

  /** A payment step a dialog asked for, with the invoice read again once it is kept. */
  private async dialogPaymentStep(
    companyId: string,
    id: string,
    call: () => Promise<void>,
  ): Promise<FormAnswer<InvoiceRow>> {
    return this.inDialog(async () => {
      await call();
      return this.api.invoice(companyId, id);
    });
  }

  /**
   * A step a dialog asked for, whose answer becomes the document on screen: its refusal handed to the dialog, never
   * kept for the page's form.
   */
  private async inDialog(call: () => Promise<InvoiceRow>): Promise<FormAnswer<InvoiceRow>> {
    const answer = await this.state.writeOrRefuse(call, (error) =>
      error instanceof InvoicesRefused ? error.refusals : [],
    );
    if (answer !== null && 'kept' in answer) this.invoiceSignal.set(answer.kept);
    return answer;
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
