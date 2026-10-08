// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { DeliveryNotesApi, DeliveryNotesRefused } from './delivery-notes-api';
import type { PickAsked } from '../shared/form/pick-api';
import type { ExportFormat } from '../shared/list/export-address';
import { PriceListsApi } from '../price-lists/price-lists-api';
import type { ResolvedPrice } from '../price-lists/price-lists-types';
import type {
  CustomerOption,
  DeliveryNoteInput,
  DeliveryNoteOptions,
  DeliveryNoteRow,
  DeliveryNoteSearch,
  DeliveryNotesError,
  DeliveryNoteCredit,
  DeliveryNoteLeft,
  DeliveryNoteStatusCounts,
  ProductOption,
  InvoiceDraftOption,
} from './delivery-notes-types';

/** The delivery notes of the company being worked in, the note open on screen and what its form offers. */
@Injectable({ providedIn: 'root' })
export class DeliveryNotesFacade {
  private readonly api = inject(DeliveryNotesApi);
  private readonly prices = inject(PriceListsApi);
  private readonly notesSignal = signal<readonly DeliveryNoteRow[]>([]);
  private readonly optionsSignal = signal<DeliveryNoteOptions | null>(null);
  private readonly noteSignal = signal<DeliveryNoteRow | null>(null);
  private readonly totalSignal = signal(0);
  private readonly statusCountsSignal = signal<DeliveryNoteStatusCounts | null>(null);
  private pageRequest = 0;
  private countsRequest = 0;
  private readonly busySignal = signal(false);
  private reads = 0;
  private readonly errorSignal = signal<DeliveryNotesError | null>(null);

  readonly notes = this.notesSignal.asReadonly();
  readonly options = this.optionsSignal.asReadonly();
  /** The note open on screen, as the API last answered with it; null while a new one is filled in. */
  readonly note = this.noteSignal.asReadonly();
  /** How many notes the last search found in all, the page shown being one part of them. */
  readonly total = this.totalSignal.asReadonly();
  /** What each status chip of the list would show; null until read, and kept while a new count is on its way. */
  readonly statusCounts = this.statusCountsSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  /** What the list screen needs besides its page: the options that name its drafts' customers. */
  async loadListContext(companyId: string): Promise<void> {
    await this.read(async () => this.optionsSignal.set(await this.api.options(companyId)));
  }

  /**
   * The few customers or products a person means while typing, and — by id — exactly the records an open note names,
   * still offered or not. A search that fails answers nothing and says so in `error`, rather than reading as "nothing
   * found": what the picker could not ask for is not the same as what does not exist.
   */
  async pickCustomers(companyId: string, asked: PickAsked): Promise<CustomerOption[]> {
    return this.pick(() => this.api.pickCustomers(companyId, asked));
  }

  /** What the product sells at for that customer and quantity; null where the price cannot be read. */
  productPrice(
    companyId: string,
    productId: string,
    customerId: string | null,
    quantity: string,
  ): Promise<ResolvedPrice | null> {
    return this.prices.productPrice(companyId, productId, customerId, quantity);
  }

  async pickProducts(companyId: string, asked: PickAsked): Promise<ProductOption[]> {
    return this.pick(() => this.api.pickProducts(companyId, asked));
  }

  /**
   * One page of the notes the search finds. Only the latest search's answer is shown: typing sends one search per
   * keystroke and they need not come back in order.
   */
  async loadPage(companyId: string, search: DeliveryNoteSearch): Promise<void> {
    const request = ++this.pageRequest;
    await this.read(async () => {
      const page = await this.api.notes(companyId, search);
      if (request !== this.pageRequest) return;
      this.notesSignal.set(page.rows);
      this.totalSignal.set(page.total);
    });
  }

  exportUrl(companyId: string, search: DeliveryNoteSearch, format: ExportFormat): string {
    return this.api.exportUrl(companyId, search, format);
  }

  /** The chips' counts for the search, the latest search's answer only, as for its page. */
  async loadStatusCounts(companyId: string, search: DeliveryNoteSearch): Promise<void> {
    const request = ++this.countsRequest;
    await this.read(async () => {
      const counts = await this.api.statusCounts(companyId, search);
      if (request === this.countsRequest) this.statusCountsSignal.set(counts);
    });
  }

  /**
   * What delivering the note would do to its customer's credit limit, or null when that could not be read. The notice
   * is advisory, so a failed read does not replace the note with an error: the notice says the limit was not checked.
   */
  async credit(companyId: string, id: string): Promise<DeliveryNoteCredit | null> {
    try {
      return await this.api.credit(companyId, id);
    } catch {
      return null;
    }
  }

  /** What the note screen needs: the form's options, and the note unless it is new. */
  async loadNote(companyId: string, id: string | null): Promise<void> {
    await this.read(async () => {
      const [options, note] = await Promise.all([
        this.api.options(companyId),
        id === null ? Promise.resolve(null) : this.api.note(companyId, id),
      ]);
      this.optionsSignal.set(options);
      this.noteSignal.set(note);
    });
  }

  /** The draft as the API kept it, or null with the reason in `error`. */
  async create(companyId: string, input: DeliveryNoteInput): Promise<DeliveryNoteRow | null> {
    return this.step(() => this.api.create(companyId, input));
  }

  async revise(
    companyId: string,
    id: string,
    input: DeliveryNoteInput,
  ): Promise<DeliveryNoteRow | null> {
    return this.step(() => this.api.revise(companyId, id, input));
  }

  /** Saves what is on screen, then numbers it: a note is never validated with content other than the one shown. */
  async reviseAndValidate(
    companyId: string,
    id: string,
    input: DeliveryNoteInput,
  ): Promise<DeliveryNoteRow | null> {
    return this.step(async () => {
      this.noteSignal.set(await this.api.revise(companyId, id, input));
      return this.api.validate(companyId, id);
    });
  }

  /** Delivered on the day given, or on the company's today when none is. */
  async deliver(
    companyId: string,
    id: string,
    deliveredOn: string | null,
  ): Promise<DeliveryNoteRow | null> {
    return this.step(() => this.api.deliver(companyId, id, deliveredOn));
  }

  async cancel(companyId: string, id: string): Promise<DeliveryNoteRow | null> {
    return this.step(() => this.api.cancel(companyId, id));
  }

  /** What of the note is still to invoice, or null with the reason in `error`. */
  async left(companyId: string, id: string): Promise<DeliveryNoteLeft | null> {
    this.errorSignal.set(null);
    try {
      return await this.api.left(companyId, id);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return null;
    }
  }

  /**
   * The id of the invoice drafted from the note, or null with the reason in `error`. With no quantities the invoice
   * takes what is left of every line; with some, only the lines named, each for its quantity.
   */
  async invoice(
    companyId: string,
    id: string,
    quantities?: Readonly<Record<string, string>>,
    intoDraftId?: string,
  ): Promise<string | null> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      return await this.api.invoice(companyId, [id], quantities, intoDraftId);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return null;
    } finally {
      this.busySignal.set(false);
    }
  }

  /**
   * The drafts a note could be added to, none when there are none or they could not be read (the reason is in `error`:
   * what could not be asked is not the same as there being nothing to add to).
   */
  async draftsOf(
    companyId: string,
    customerId: string,
    establishmentId: string | null,
  ): Promise<InvoiceDraftOption[] | null> {
    try {
      return await this.api.draftsOf(companyId, customerId, establishmentId);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return null;
    }
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
    this.reads++;
    this.busySignal.set(true);
    // Cleared when a read starts, never when one ends, so a read answering after another failed does not hide it.
    this.errorSignal.set(null);
    try {
      await load();
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      // Several reads run at once (a list and what its filters name): busy until the last one answers.
      if (--this.reads === 0) this.busySignal.set(false);
    }
  }

  private async step(call: () => Promise<DeliveryNoteRow>): Promise<DeliveryNoteRow | null> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      const note = await call();
      this.noteSignal.set(note);
      return note;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return null;
    } finally {
      this.busySignal.set(false);
    }
  }
}

function codeOf(error: unknown): DeliveryNotesError {
  return error instanceof DeliveryNotesRefused ? error.code : 'network';
}
