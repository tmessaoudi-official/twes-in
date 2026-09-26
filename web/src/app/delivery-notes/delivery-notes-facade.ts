// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { DeliveryNotesApi, DeliveryNotesRefused } from './delivery-notes-api';
import type { PickAsked } from '../shared/form/pick-api';
import type {
  CustomerOption,
  DeliveryNoteInput,
  DeliveryNoteOptions,
  DeliveryNoteRow,
  DeliveryNoteSearch,
  DeliveryNotesError,
  DeliveryNoteStatusCounts,
  ProductOption,
} from './delivery-notes-types';

/** The delivery notes of the company being worked in, the note open on screen and what its form offers. */
@Injectable({ providedIn: 'root' })
export class DeliveryNotesFacade {
  private readonly api = inject(DeliveryNotesApi);
  private readonly notesSignal = signal<readonly DeliveryNoteRow[]>([]);
  private readonly optionsSignal = signal<DeliveryNoteOptions | null>(null);
  private readonly noteSignal = signal<DeliveryNoteRow | null>(null);
  private readonly totalSignal = signal(0);
  private readonly statusCountsSignal = signal<DeliveryNoteStatusCounts | null>(null);
  private pageRequest = 0;
  private countsRequest = 0;
  private readonly busySignal = signal(false);
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

  /** The chips' counts for the search, the latest search's answer only, as for its page. */
  async loadStatusCounts(companyId: string, search: DeliveryNoteSearch): Promise<void> {
    const request = ++this.countsRequest;
    await this.read(async () => {
      const counts = await this.api.statusCounts(companyId, search);
      if (request === this.countsRequest) this.statusCountsSignal.set(counts);
    });
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

  /** The id of the invoice drafted from the note, or null with the reason in `error`. */
  async invoice(companyId: string, id: string): Promise<string | null> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      return await this.api.invoice(companyId, [id]);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return null;
    } finally {
      this.busySignal.set(false);
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
