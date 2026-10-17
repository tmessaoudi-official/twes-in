// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed } from '@angular/core';
import type { DescriptorFormGroup } from './form-builder';
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

/**
 * liveRecord for an editor opened inside a list. The record is the row the list holds now, found by the id of the
 * row that was opened: the row kept when it was opened never changes, so another person's save, read again by the
 * list, would never reach the form through it. A save sends the version the form stands on. A form the page builds
 * again while the row stays open keeps the base it stood on (`keepsBaseOnRebuild`). Call it in the page's injection
 * context.
 */
export function listRecord<R extends VersionedRow>(options: ListRecordOptions<R>): LiveRecord {
  const saved = computed(() => {
    const editing = options.editing();
    if (editing === null || editing === 'new') return null;
    return options.rows().find((row) => row.id === editing.id) ?? null;
  });
  return liveRecord({
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
}
