// SPDX-License-Identifier: AGPL-3.0-or-later

import type { DescriptorFormGroup } from './form-builder';
import type { FieldValue, FormValues } from './form-types';

/** A field both people changed: what is typed here, and what the other person saved. */
export interface FieldConflict {
  readonly field: string;
  readonly mine: FieldValue;
  readonly theirs: FieldValue;
}

export interface MergeOutcome {
  /** The fields that took the other person's value, for the form to highlight. */
  readonly updated: readonly string[];
  readonly conflicts: readonly FieldConflict[];
}

/**
 * Brings a form open on a record up to the version another person just saved (docs/SPEC.md § 7, 2026-09-17), field
 * by field against `base`, the saved version the form was built from. A field that still holds its base value was
 * not touched here and takes the new value; a field typed here keeps what was typed, and is a conflict only where
 * the other person changed it too. The caller keeps the new version as its base afterwards.
 */
export function mergeSavedVersion(
  form: DescriptorFormGroup,
  base: FormValues,
  incoming: FormValues,
): MergeOutcome {
  const updated: string[] = [];
  const conflicts: FieldConflict[] = [];
  for (const [field, control] of Object.entries(form.controls)) {
    if (!(field in incoming) || !(field in base)) continue;
    const theirs = incoming[field];
    const before = base[field];
    if (same(theirs, before)) continue;
    const mine = control.value;
    if (same(mine, before)) {
      control.setValue(theirs, { emitEvent: true });
      updated.push(field);
    } else if (!same(mine, theirs)) {
      conflicts.push({ field, mine, theirs });
    }
  }
  return { updated, conflicts };
}

function same(a: FieldValue | undefined, b: FieldValue | undefined): boolean {
  return (a ?? null) === (b ?? null) || String(a ?? '') === String(b ?? '');
}
