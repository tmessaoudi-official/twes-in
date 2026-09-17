// SPDX-License-Identifier: AGPL-3.0-or-later

import { DestroyRef, effect, inject, untracked } from '@angular/core';
import { Feedback } from '../feedback/feedback';
import { RequestActivity } from '../feedback/request-activity';
import { type LiveChange, LiveChanges } from '../realtime/live-changes';
import type { DescriptorFormGroup } from './form-builder';
import type { FormValues } from './form-types';
import type { FieldConflict } from './form-merge';
import { type ChangedBy, RecordSync } from './record-sync';

export interface LiveRecordOptions {
  /** The API's audit entity type the record is saved under (`customer`, `invoice`…). */
  readonly kind: string;
  /** The open record's id; null while a new one is being written. */
  readonly id: () => string | null;
  readonly form: () => DescriptorFormGroup | null;
  /** Reads the record again into the page's state. */
  readonly reload: () => Promise<unknown>;
  /** The record as it is now saved, as the form's values; null when it cannot be shown. */
  readonly saved: () => FormValues | null;
  /**
   * Which announced changes are this record's; by default, those naming its id. A settings panel names its chain
   * instead, since a setting is saved under its own row's id.
   */
  readonly matches?: (change: LiveChange) => boolean;
  /** What the page edits outside the form and merges as one field each, such as a document's lines. */
  readonly parts?: readonly LivePart[];
}

/**
 * Something edited outside the form that merges as one field (docs/SPEC.md § 7: a document's lines are one field).
 * Each side is a comparable text: equal texts are the same content.
 */
export interface LivePart {
  readonly field: string;
  /** What is saved now; null when it cannot be read. */
  readonly saved: () => string | null;
  /** What the page shows, typed or not. */
  readonly shown: () => string;
  /** Shows what is saved in place of what is shown. */
  readonly take: () => void;
}

/** A RecordSync that also knows this tab saved: the form is the saved version, and nothing in it is typed any more. */
export class LiveRecord extends RecordSync {
  /** What each part showed when the form last stood on the saved version. */
  private readonly partBases = new Map<string, string>();

  constructor(private readonly parts: readonly LivePart[] = []) {
    super();
  }

  override track(saved: FormValues): void {
    super.track(saved);
    // What is saved, not what is shown: a page may still be filling a part from the record (its document taxes) when
    // the form it built is tracked.
    for (const part of this.parts) this.partBases.set(part.field, part.saved() ?? part.shown());
  }

  /** Merges the saved version of the form and of every part. */
  merge(
    form: DescriptorFormGroup,
    incoming: FormValues,
    actor: ChangedBy | null,
  ): 'updated' | 'editing' | 'unchanged' {
    const updated: string[] = [];
    const conflicts: FieldConflict[] = [];
    let typing = false;
    for (const part of this.parts) {
      const base = this.partBases.get(part.field);
      const theirs = part.saved();
      if (base === undefined || theirs === null) continue;
      const mine = part.shown();
      typing ||= mine !== base;
      if (theirs === base) continue;
      if (mine === base) {
        part.take();
        updated.push(part.field);
      } else if (mine !== theirs) {
        conflicts.push({ field: part.field, mine: '', theirs: '' });
      }
      this.partBases.set(part.field, theirs);
    }
    return this.receive(form, incoming, actor, { updated, conflicts, typing });
  }

  override takeTheirs(form: DescriptorFormGroup, field: string): void {
    const part = this.parts.find((candidate) => candidate.field === field);
    if (part === undefined) {
      super.takeTheirs(form, field);
      return;
    }
    part.take();
    this.forget(field);
  }

  override reloadSaved(form: DescriptorFormGroup): void {
    for (const part of this.parts) part.take();
    super.reloadSaved(form);
  }

  savedHere(form: DescriptorFormGroup): void {
    this.track(form.getRawValue());
    form.markAsPristine();
    // What this tab saved is what it shows, whatever shape the API gave the same content back in.
    for (const part of this.parts) this.partBases.set(part.field, part.shown());
  }

  /** The choice made on one field both sides changed: the saved value, or what is typed here. */
  resolve(
    form: DescriptorFormGroup,
    { field, choice }: { field: string; choice: 'theirs' | 'mine' },
  ): void {
    if (choice === 'theirs') this.takeTheirs(form, field);
    else this.keepMine(field);
  }
}

/**
 * Keeps an open editor on the saved version while other tabs and people save the same record (docs/SPEC.md § 7,
 * 2026-09-17): the page's form is tracked each time it is built, and each change announced for this record is read
 * again quietly and merged into it. A quiet form takes it with a toast naming who saved it; a form being typed in
 * keeps what is typed and shows the banner. Call it in the page's injection context.
 */
export function liveRecord(options: LiveRecordOptions): LiveRecord {
  const sync = new LiveRecord(options.parts);
  const activity = inject(RequestActivity);
  const feedback = inject(Feedback);

  effect(() => {
    const form = options.form();
    if (form !== null) untracked(() => sync.track(form.getRawValue()));
  });

  const receive = async (changes: readonly LiveChange[]): Promise<void> => {
    const id = options.id();
    const matches = options.matches ?? ((candidate: LiveChange) => candidate.id === id);
    const change = changes.filter(matches).at(-1);
    if (change === undefined || (options.matches === undefined && id === null)) return;
    const name = change.actor?.name;
    const said = name ? { name } : {};
    if (change.action.endsWith('.deleted')) {
      feedback.notice(name ? 'live.deleted' : 'live.deleted_someone', said);
      return;
    }
    await activity.quietly(options.reload);
    const form = options.form();
    const incoming = options.saved();
    if (form === null || incoming === null) return;
    if (sync.merge(form, incoming, change.actor) === 'updated') {
      feedback.notice(name ? 'live.notice' : 'live.notice_someone', said);
    }
  };
  inject(LiveChanges).on([options.kind], (changes) => void receive(changes), inject(DestroyRef));

  return sync;
}
