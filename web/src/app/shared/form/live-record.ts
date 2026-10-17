// SPDX-License-Identifier: AGPL-3.0-or-later

import { DestroyRef, effect, inject, untracked } from '@angular/core';
import { Feedback } from '../feedback/feedback';
import { RequestActivity } from '../feedback/request-activity';
import { type LiveChange, LiveChanges } from '../realtime/live-changes';
import type { DescriptorFormGroup } from './form-builder';
import type { FormValues } from './form-types';
import type { FieldConflict } from './form-merge';
import { type ChangedBy, RecordSync } from './record-sync';

export interface LiveRecordOptions<V = number | null> {
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
  /**
   * The version of the record as it is now saved, which a save sends back; null for a record that has none yet. A
   * settings page answers one version per key, each row having its own.
   */
  readonly version?: () => V;
  /**
   * The page builds its form again while the record stays open (the options it offers follow a list read again), over
   * what was typed or over the record as it was opened, never over what is saved since. Such a form keeps the base and
   * the version the open form stood on: tracked as the saved version, what was typed would read as saved, and a save
   * made since would be merged over it.
   */
  readonly keepsBaseOnRebuild?: boolean;
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
export class LiveRecord<V = number | null> extends RecordSync {
  /** What each part showed when the form last stood on the saved version. */
  private readonly partBases = new Map<string, string>();
  private baseVersion: V | null = null;

  /**
   * Reads the record again and merges it after a save was refused as made over a version saved since; liveRecord
   * gives it what it needs to read and to say so.
   */
  savedElsewhere: () => Promise<void> = async () => undefined;

  constructor(
    private readonly parts: readonly LivePart[] = [],
    private readonly saved: () => FormValues | null = () => null,
    private readonly savedVersion: () => V | null = () => null,
  ) {
    super();
  }

  /**
   * The version of the record the form stands on, which a save sends back: the API refuses it once someone else saved
   * a later one. Not the version last read: a record read again without being merged is not what the form shows.
   */
  version(): V | null {
    return this.baseVersion;
  }

  override track(saved: FormValues): void {
    super.track(saved);
    this.baseVersion = this.savedVersion();
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
    const outcome = this.receive(form, incoming, actor, { updated, conflicts, typing });
    // The form now stands on what it merged, whatever it took of it.
    this.baseVersion = this.savedVersion();
    return outcome;
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

  /**
   * This tab saved: the form shows what the API kept, in the shape it answered (a price at the currency's scale),
   * so a later save elsewhere is compared with that and never mistaken for a change of what was typed here. The
   * form keeps its controls, so nothing loses focus.
   */
  savedHere(form: DescriptorFormGroup): void {
    const saved = this.saved();
    if (saved === null) {
      this.track(form.getRawValue());
      form.markAsPristine();
      return;
    }
    for (const [field, control] of Object.entries(form.controls)) {
      if (field in saved && String(control.value ?? '') !== String(saved[field] ?? '')) {
        control.setValue(saved[field], { emitEvent: false });
      }
    }
    form.markAsPristine();
    for (const part of this.parts) {
      const theirs = part.saved();
      if (theirs !== null && theirs !== part.shown()) part.take();
    }
    this.track(saved);
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
export function liveRecord<V = number | null>(options: LiveRecordOptions<V>): LiveRecord<V> {
  const sync = new LiveRecord<V>(options.parts, options.saved, options.version);
  const activity = inject(RequestActivity);
  const feedback = inject(Feedback);

  // The announcement of that other save never reached this tab: what it changed is merged now, as it would have been,
  // and nothing was saved here, which stays said until it is closed.
  sync.savedElsewhere = async () => {
    await activity.quietly(options.reload);
    const form = options.form();
    const incoming = options.saved();
    if (form !== null && incoming !== null) sync.merge(form, incoming, null);
    feedback.failure('live.saved_since');
  };

  // The id last tracked; undefined while no form is open.
  let tracked: string | null | undefined;
  effect(() => {
    const form = options.form();
    if (form === null) {
      tracked = undefined;
      return;
    }
    untracked(() => {
      const id = options.id();
      if (options.keepsBaseOnRebuild === true && tracked !== undefined && tracked === id) return;
      tracked = id;
      sync.track(form.getRawValue());
    });
  });

  const receive = async (changes: readonly LiveChange[]): Promise<void> => {
    const id = options.id();
    const own = options.matches ?? ((candidate: LiveChange) => candidate.id === id);
    // A change of many records names none of them, and this one may be among them.
    const change = changes.filter((candidate) => candidate.bulk === true || own(candidate)).at(-1);
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
