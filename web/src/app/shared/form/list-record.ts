// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, linkedSignal, type Signal, untracked } from '@angular/core';
import type { DescriptorFormGroup } from './form-builder';
import { unsavedChanges } from './dirty-count';
import type { FormValues } from './form-types';
import { type LiveRecord, liveRecord } from './live-record';

/** A row a list holds, which an editor opened inside that list revises. */
export interface VersionedRow {
  readonly id: string;
  /** The version read, which a revision sends back. */
  readonly version: number;
}

export interface ListRecordOptions<R extends VersionedRow> {
  /** The API's audit entity type the rows are saved under (`unit`, `tax_component`…). */
  readonly kind: string;
  /** The row being revised; `'new'` while one is being added, null while no form is open. */
  readonly editing: () => R | 'new' | null;
  /** The rows as the list holds them now. */
  readonly rows: () => readonly R[];
  readonly form: () => DescriptorFormGroup | null;
  /** Reads the list again. */
  readonly reload: () => Promise<unknown>;
  /** A row as the form's values. */
  readonly values: (row: R) => FormValues;
}

/** A list's editor as liveRecord keeps it, with what is typed in it and not saved. */
export type ListRecord = LiveRecord & {
  /** How many fields hold something other than the row as the list holds it, or than the blank form of a new one. */
  readonly changes: Signal<number>;
};

/**
 * liveRecord for an editor opened inside a list. The record is the row the list holds now, found by the id of the
 * row that was opened: the row kept when it was opened never changes, so another person's save, read again by the
 * list, would never reach the form through it. A save sends the version the form stands on. A form the page builds
 * again while the row stays open keeps the base it stood on (`keepsBaseOnRebuild`). Call it in the page's injection
 * context.
 *
 * It counts what is typed and not saved here rather than on each page: an editor opened in a list holds unsaved work
 * as a record page does, so leaving or reloading asks first, and a list added later cannot forget to take part.
 */
export function listRecord<R extends VersionedRow>(options: ListRecordOptions<R>): ListRecord {
  const saved = computed(() => {
    const editing = options.editing();
    if (editing === null || editing === 'new') return null;
    return options.rows().find((row) => row.id === editing.id) ?? null;
  });
  const record = liveRecord({
    kind: options.kind,
    id: () => saved()?.id ?? null,
    form: options.form,
    reload: options.reload,
    saved: () => {
      const row = saved();
      return row === null ? null : options.values(row);
    },
    version: () => saved()?.version ?? null,
    keepsBaseOnRebuild: true,
  });
  // A new row is measured against its form as it opened: a form the page builds again over a list read meanwhile
  // carries what was typed, and taking that as the baseline would read the typing as saved.
  const opened = linkedSignal({
    source: options.editing,
    computation: (editing) =>
      editing === 'new'
        ? untracked(() => structuredClone(options.form()?.getRawValue() ?? null))
        : null,
  });
  const changes = unsavedChanges(
    computed(options.form),
    computed(() => {
      const row = saved();
      if (row !== null) return options.values(row);
      return options.editing() === 'new' ? opened() : null;
    }),
  );
  return Object.assign(record, { changes });
}
