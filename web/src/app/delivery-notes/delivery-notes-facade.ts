// SPDX-License-Identifier: AGPL-3.0-or-later

import { type FormAnswer, LatestRequest, requestState } from '../shared/feedback/request-state';
import { inject, Injectable } from '@angular/core';
import { DeliveryNotesApi, DeliveryNotesRefused } from './delivery-notes-api';
import type { PickAsked } from '../shared/form/pick-api';
import type { PreviewBody } from '../shared/documents/document-figures';
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
  InvoicingCheck,
} from './delivery-notes-types';
import { sessionSignal } from '../shared/session/session-data';
import type { FieldRefusal } from '../shared/form/field-refusals';
import type { ReceiverChoice, ReceiverContact } from '../shared/receivers/receiver';

/** The delivery notes of the company being worked in, the note open on screen and what its form offers. */
@Injectable({ providedIn: 'root' })
export class DeliveryNotesFacade {
  private readonly api = inject(DeliveryNotesApi);
  private readonly prices = inject(PriceListsApi);
  private readonly notesSignal = sessionSignal<readonly DeliveryNoteRow[]>([]);
  private readonly optionsSignal = sessionSignal<DeliveryNoteOptions | null>(null);
  private readonly noteSignal = sessionSignal<DeliveryNoteRow | null>(null);
  private readonly totalSignal = sessionSignal(0);
  private readonly statusCountsSignal = sessionSignal<DeliveryNoteStatusCounts | null>(null);
  private readonly refusalsSignal = sessionSignal<readonly FieldRefusal[]>([]);
  private readonly pageRequests = new LatestRequest();
  private readonly countsRequests = new LatestRequest();
  private readonly state = requestState(codeOf);

  readonly notes = this.notesSignal.asReadonly();
  readonly options = this.optionsSignal.asReadonly();
  /** The note open on screen, as the API last answered with it; null while a new one is filled in. */
  readonly note = this.noteSignal.asReadonly();
  /** How many notes the last search found in all, the page shown being one part of them. */
  readonly total = this.totalSignal.asReadonly();
  /** What each status chip of the list would show; null until read, and kept while a new count is on its way. */
  readonly statusCounts = this.statusCountsSignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;
  /** Each field the last step was refused on, with why; empty after a step the API took. */
  readonly refusals = this.refusalsSignal.asReadonly();

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
    const current = this.pageRequests.next();
    await this.read(async () => {
      const page = await this.api.notes(companyId, search);
      if (!current()) return;
      this.notesSignal.set(page.rows);
      this.totalSignal.set(page.total);
    });
  }

  exportUrl(companyId: string, search: DeliveryNoteSearch, format: ExportFormat): string {
    return this.api.exportUrl(companyId, search, format);
  }

  /** The chips' counts for the search, the latest search's answer only, as for its page. */
  async loadStatusCounts(companyId: string, search: DeliveryNoteSearch): Promise<void> {
    const current = this.countsRequests.next();
    await this.read(async () => {
      const counts = await this.api.statusCounts(companyId, search);
      if (current()) this.statusCountsSignal.set(counts);
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
    version: number | null,
  ): Promise<DeliveryNoteRow | null> {
    return this.step(() => this.api.revise(companyId, id, input, version));
  }

  /**
   * What is typed would come to, or null when the API refuses it as it stands: a draft being typed is often not one
   * yet, so a refusal here is no error to report, and the screen's error is left alone.
   */
  async preview(
    companyId: string,
    id: string | null,
    input: DeliveryNoteInput,
  ): Promise<PreviewBody | null> {
    try {
      return await this.api.preview(companyId, id, input);
    } catch {
      return null;
    }
  }

  /**
   * Saves what is on screen, then numbers it: a note is never validated with content other than the one shown.
   * `revised` is told once the save is kept, whatever validating then answers, so the form stands on what was saved.
   */
  async reviseAndValidate(
    companyId: string,
    id: string,
    input: DeliveryNoteInput,
    version: number | null,
    revised: () => void,
    confirmedShort = false,
  ): Promise<DeliveryNoteRow | null> {
    return this.step(async () => {
      this.noteSignal.set(await this.api.revise(companyId, id, input, version));
      revised();
      return this.api.validate(companyId, id, confirmedShort);
    });
  }

  /** Saves what is on screen, then numbers it and marks it delivered today in one step; `revised` as above. */
  async reviseValidateAndDeliver(
    companyId: string,
    id: string,
    input: DeliveryNoteInput,
    version: number | null,
    revised: () => void,
    confirmedShort = false,
  ): Promise<DeliveryNoteRow | null> {
    return this.step(async () => {
      this.noteSignal.set(await this.api.revise(companyId, id, input, version));
      revised();
      return this.api.validateAndDeliver(companyId, id, null, confirmedShort);
    });
  }

  /** Delivered at the moment given on the company's clock, or now when none is. */
  /** The note marked delivered, at the moment given or now; asked in a dialog, which says a refusal under its field. */
  async deliver(
    companyId: string,
    id: string,
    delivered: string | null,
    receivedBy: ReceiverChoice | null = null,
  ): Promise<FormAnswer<DeliveryNoteRow>> {
    const answer = await this.state.writeOrRefuse(
      () => this.api.deliver(companyId, id, delivered, receivedBy),
      (error) => (error instanceof DeliveryNotesRefused ? error.refusals : []),
    );
    if (answer !== null && 'kept' in answer) this.noteSignal.set(answer.kept);
    return answer;
  }

  /** Who received, put right on a numbered note; asked in a dialog, which says a refusal under its field. */
  async correctReceiver(
    companyId: string,
    id: string,
    receivedBy: ReceiverChoice | null,
  ): Promise<FormAnswer<DeliveryNoteRow>> {
    const answer = await this.state.writeOrRefuse(
      () => this.api.correctReceiver(companyId, id, receivedBy),
      (error) => (error instanceof DeliveryNotesRefused ? error.refusals : []),
    );
    if (answer !== null && 'kept' in answer) this.noteSignal.set(answer.kept);
    return answer;
  }

  /** The customer's contacts « Reçu par » offers; none when they cannot be read, which the screen says. */
  async receivers(companyId: string, customerId: string): Promise<ReceiverContact[]> {
    return this.pick(() => this.api.receivers(companyId, customerId));
  }

  async cancel(companyId: string, id: string): Promise<DeliveryNoteRow | null> {
    return this.step(() => this.api.cancel(companyId, id));
  }

  /** What of the note is still to invoice, or null with the reason in `error`. */
  async left(companyId: string, id: string): Promise<DeliveryNoteLeft | null> {
    this.state.clearError();
    try {
      return await this.api.left(companyId, id);
    } catch (error) {
      this.state.fail(error);
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
    return this.state.writeAnswer(() => this.api.invoice(companyId, [id], quantities, intoDraftId));
  }

  /** An invoice for part of the note, asked in a dialog, which says a line's refused quantity on its line. */
  async invoicePart(
    companyId: string,
    id: string,
    quantities: Readonly<Record<string, string>>,
    intoDraftId?: string,
  ): Promise<FormAnswer<string>> {
    return this.state.writeOrRefuse(
      () => this.api.invoice(companyId, [id], quantities, intoDraftId),
      (error) => (error instanceof DeliveryNotesRefused ? error.refusals : []),
    );
  }

  /*
   * The list's « Facturer » on several notes. These three throw `DeliveryNotesRefused` and leave `error` alone: the
   * list reads `error` as a page that failed to load, and what went wrong here is said by the action itself.
   */

  /** What invoicing the selected notes would do with each, as the API checks it; nothing changes. */
  async invoicingCheck(
    companyId: string,
    ids: readonly string[],
    intoDraftId: string | null = null,
  ): Promise<InvoicingCheck> {
    return this.api.invoicingCheck(companyId, ids, intoDraftId);
  }

  /** The drafts of the customer and establishment the selected notes would be added to. */
  async draftsToAddTo(
    companyId: string,
    customerId: string,
    establishmentId: string | null,
  ): Promise<InvoiceDraftOption[]> {
    return this.api.draftsOf(companyId, customerId, establishmentId);
  }

  /** The id of the invoice drafted from the notes, each for what is left of it, new or added to `intoDraftId`. */
  async invoiceNotes(
    companyId: string,
    ids: readonly string[],
    intoDraftId?: string,
  ): Promise<string> {
    return this.api.invoice(companyId, ids, undefined, intoDraftId);
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
      this.state.fail(error);
      return null;
    }
  }

  pdfUrl(companyId: string, id: string): string {
    return this.api.pdfUrl(companyId, id);
  }

  deliveredCopyUrl(companyId: string, id: string): string {
    return this.api.deliveredCopyUrl(companyId, id);
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

  private async step(call: () => Promise<DeliveryNoteRow>): Promise<DeliveryNoteRow | null> {
    this.refusalsSignal.set([]);
    return this.state.writeAnswer(
      async () => {
        const note = await call();
        this.noteSignal.set(note);
        return note;
      },
      {
        failed: (error) =>
          this.refusalsSignal.set(error instanceof DeliveryNotesRefused ? error.refusals : []),
      },
    );
  }
}

function codeOf(error: unknown): DeliveryNotesError {
  return error instanceof DeliveryNotesRefused ? error.code : 'network';
}
