// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { DeliveryNotesApi, DeliveryNotesRefused } from './delivery-notes-api';
import type {
  DeliveryNoteInput,
  DeliveryNoteOptions,
  DeliveryNoteRow,
  DeliveryNotesError,
} from './delivery-notes-types';

/** The delivery notes of the company being worked in, the note open on screen and what its form offers. */
@Injectable({ providedIn: 'root' })
export class DeliveryNotesFacade {
  private readonly api = inject(DeliveryNotesApi);
  private readonly notesSignal = signal<readonly DeliveryNoteRow[]>([]);
  private readonly optionsSignal = signal<DeliveryNoteOptions | null>(null);
  private readonly noteSignal = signal<DeliveryNoteRow | null>(null);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<DeliveryNotesError | null>(null);

  readonly notes = this.notesSignal.asReadonly();
  readonly options = this.optionsSignal.asReadonly();
  /** The note open on screen, as the API last answered with it; null while a new one is filled in. */
  readonly note = this.noteSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  /** The list, with the options that name its drafts' customers. */
  async loadList(companyId: string): Promise<void> {
    await this.read(async () => {
      const [notes, options] = await Promise.all([
        this.api.notes(companyId),
        this.api.options(companyId),
      ]);
      this.notesSignal.set(notes);
      this.optionsSignal.set(options);
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
