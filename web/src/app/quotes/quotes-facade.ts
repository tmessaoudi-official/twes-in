// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import type {
  CustomerOption,
  InvoiceOptions,
  ProductOption,
  ResolvedPrice,
} from '../invoices/invoices-types';
import type { LineCatalogue } from '../invoices/line-catalogue';
import type { PickAsked } from '../shared/form/pick-api';
import { QuotesApi, QuotesRefused } from './quotes-api';
import type {
  DepositShare,
  QuoteAnswer,
  QuoteAttachment,
  QuoteInput,
  QuoteRow,
  QuoteSearch,
  QuotesError,
  QuoteStatusCounts,
} from './quotes-types';
import type { PreviewBody } from '../shared/documents/document-figures';

/**
 * The quotes of the company being worked in, the quote open on screen, its files and what its form offers. It is
 * also the quote screen's `LineCatalogue`, so the lines editor asks for products under the quote's permission.
 */
@Injectable({ providedIn: 'root' })
export class QuotesFacade implements LineCatalogue {
  private readonly api = inject(QuotesApi);
  private readonly quotesSignal = signal<readonly QuoteRow[]>([]);
  private readonly optionsSignal = signal<InvoiceOptions | null>(null);
  private readonly quoteSignal = signal<QuoteRow | null>(null);
  private readonly attachmentsSignal = signal<readonly QuoteAttachment[]>([]);
  private readonly totalSignal = signal(0);
  private readonly statusCountsSignal = signal<QuoteStatusCounts | null>(null);
  private pageRequest = 0;
  private countsRequest = 0;
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<QuotesError | null>(null);

  readonly quotes = this.quotesSignal.asReadonly();
  readonly options = this.optionsSignal.asReadonly();
  /** How many quotes the last search found in all, the page shown being one part of them. */
  readonly total = this.totalSignal.asReadonly();
  /** What each status chip of the list would show; null until read. */
  readonly statusCounts = this.statusCountsSignal.asReadonly();
  /** The quote open on screen, as the API last answered with it; null while a new one is filled in. */
  readonly quote = this.quoteSignal.asReadonly();
  /** The files attached to the quote open on screen, such as the copy the customer signed. */
  readonly attachments = this.attachmentsSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  /** What the list needs besides its page: the currency's scale its totals are shown at. */
  async loadListContext(companyId: string): Promise<void> {
    await this.read(async () => this.optionsSignal.set(await this.api.options(companyId)));
  }

  /** One page of the quotes the search finds; only the latest search's answer is shown. */
  async loadPage(companyId: string, search: QuoteSearch): Promise<void> {
    const request = ++this.pageRequest;
    await this.read(async () => {
      const page = await this.api.quotes(companyId, search);
      if (request !== this.pageRequest) return;
      this.quotesSignal.set(page.rows);
      this.totalSignal.set(page.total);
    });
  }

  async loadStatusCounts(companyId: string, search: QuoteSearch): Promise<void> {
    const request = ++this.countsRequest;
    await this.read(async () => {
      const counts = await this.api.statusCounts(companyId, search);
      if (request === this.countsRequest) this.statusCountsSignal.set(counts);
    });
  }

  /** What the quote screen needs: the form's options, and the quote and its files unless it is new. */
  async loadQuote(companyId: string, id: string | null): Promise<void> {
    await this.read(async () => {
      const [options, quote, attachments] = await Promise.all([
        this.api.options(companyId),
        id === null ? Promise.resolve(null) : this.api.quote(companyId, id),
        id === null ? Promise.resolve([]) : this.api.attachments(companyId, id),
      ]);
      this.optionsSignal.set(options);
      this.quoteSignal.set(quote);
      this.attachmentsSignal.set(attachments);
    });
  }

  /** A search that fails answers nothing and says so in `error`, rather than reading as "nothing found". */
  async pickCustomers(companyId: string, asked: PickAsked): Promise<CustomerOption[]> {
    return this.pick(() => this.api.pickCustomers(companyId, asked));
  }

  async pickProducts(companyId: string, asked: PickAsked): Promise<ProductOption[]> {
    return this.pick(() => this.api.pickProducts(companyId, asked));
  }

  async productPrice(
    companyId: string,
    productId: string,
    customerId: string | null,
    quantity: string,
  ): Promise<ResolvedPrice | null> {
    return this.api.productPrice(companyId, productId, customerId, quantity);
  }

  /** The draft as the API kept it, or null with the reason in `error`. */
  async create(companyId: string, input: QuoteInput): Promise<QuoteRow | null> {
    return this.step(() => this.api.create(companyId, input));
  }

  async revise(companyId: string, id: string, input: QuoteInput): Promise<QuoteRow | null> {
    return this.step(() => this.api.revise(companyId, id, input));
  }

  /**
   * What is typed would come to, or null when the API refuses it as it stands: a draft being typed is often not one
   * yet, so a refusal here is no error to report, and the screen's error is left alone.
   */
  async preview(
    companyId: string,
    id: string | null,
    input: QuoteInput,
  ): Promise<PreviewBody | null> {
    try {
      return await this.api.preview(companyId, id, input);
    } catch {
      return null;
    }
  }

  /** Saves what is on screen, then marks it sent: a quote is never numbered with content other than the one shown. */
  async reviseAndSend(companyId: string, id: string, input: QuoteInput): Promise<QuoteRow | null> {
    return this.step(async () => {
      this.quoteSignal.set(await this.api.revise(companyId, id, input));
      return this.api.send(companyId, id);
    });
  }

  /**
   * The customer's yes, then the signed copy when one was given. A file refused after the answer is kept leaves the
   * quote accepted, with the refusal in `error`: the answer is not undone for a scan.
   */
  async accept(
    companyId: string,
    id: string,
    answer: QuoteAnswer,
    signed: File | null,
  ): Promise<QuoteRow | null> {
    const accepted = await this.step(() => this.api.accept(companyId, id, answer));
    if (accepted !== null && signed !== null) await this.attach(companyId, id, signed);
    return accepted;
  }

  async refuse(companyId: string, id: string, answer: QuoteAnswer): Promise<QuoteRow | null> {
    return this.step(() => this.api.refuse(companyId, id, answer));
  }

  async cancel(companyId: string, id: string): Promise<QuoteRow | null> {
    return this.step(() => this.api.cancel(companyId, id));
  }

  /** The accepted quote drafted into a new invoice; the answer names it. */
  async invoice(companyId: string, id: string): Promise<QuoteRow | null> {
    return this.step(() => this.api.invoice(companyId, id));
  }

  /** The quote with its new deposit listed last, or null with the reason in `error`. */
  async deposit(companyId: string, id: string, share: DepositShare): Promise<QuoteRow | null> {
    return this.step(() => this.api.deposit(companyId, id, share));
  }

  /** Attaches a file, then reads the quote's files and count again. */
  async attach(companyId: string, id: string, file: File): Promise<boolean> {
    return this.fileStep(companyId, id, () => this.api.attach(companyId, id, file));
  }

  async detach(companyId: string, id: string, attachmentId: string): Promise<boolean> {
    return this.fileStep(companyId, id, () => this.api.detach(companyId, id, attachmentId));
  }

  attachmentUrl(companyId: string, id: string, attachmentId: string): string {
    return this.api.attachmentUrl(companyId, id, attachmentId);
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

  private async step(call: () => Promise<QuoteRow>): Promise<QuoteRow | null> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      const quote = await call();
      this.quoteSignal.set(quote);
      return quote;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return null;
    } finally {
      this.busySignal.set(false);
    }
  }

  private async fileStep(
    companyId: string,
    id: string,
    call: () => Promise<unknown>,
  ): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      await call();
      const [attachments, quote] = await Promise.all([
        this.api.attachments(companyId, id),
        this.api.quote(companyId, id),
      ]);
      this.attachmentsSignal.set(attachments);
      this.quoteSignal.set(quote);
      return true;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }
}

function codeOf(error: unknown): QuotesError {
  return error instanceof QuotesRefused ? error.code : 'network';
}
