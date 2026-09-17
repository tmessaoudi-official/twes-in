// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import type { DescriptorFormGroup } from './form-builder';
import { type FieldConflict, mergeSavedVersion } from './form-merge';
import type { FormValues } from './form-types';

export interface PartsOutcome {
  readonly updated: readonly string[];
  readonly conflicts: readonly FieldConflict[];
  readonly typing: boolean;
}

export interface ChangedBy {
  readonly id: string;
  readonly name: string | null;
}

/**
 * What an editor knows about the saved version of the record it shows, while another person may save it too
 * (docs/SPEC.md § 7, 2026-09-17). The page tracks the version it built its form from and each version it saves;
 * when another person's version arrives, the fields nobody touched here take it and are highlighted, typed fields
 * are kept, and a field both changed waits for a choice. `receive` answers `updated` when the form was quiet (the
 * page says so with a notice), `editing` when something was being typed (the page shows the banner instead), and
 * `unchanged` when the saved version shows nothing new here.
 */
export class RecordSync {
  private base: FormValues | null = null;
  private readonly updatedSignal = signal<ReadonlySet<string>>(new Set());
  private readonly conflictsSignal = signal<readonly FieldConflict[]>([]);
  private readonly changedBySignal = signal<ChangedBy | null>(null);

  readonly updated = this.updatedSignal.asReadonly();
  readonly conflicts = this.conflictsSignal.asReadonly();
  /** Who changed the record while something was being typed here; null otherwise. */
  readonly changedBy = this.changedBySignal.asReadonly();

  /** The saved version the form now stands on: the one it was built from, or the one this tab just saved. */
  track(saved: FormValues): void {
    this.base = saved;
    this.updatedSignal.set(new Set());
    this.conflictsSignal.set([]);
    this.changedBySignal.set(null);
  }

  /** `parts` is what the page compared outside the form (its lines): taken, both changed, or being edited. */
  receive(
    form: DescriptorFormGroup,
    incoming: FormValues,
    actor: ChangedBy | null,
    parts: PartsOutcome = { updated: [], conflicts: [], typing: false },
  ): 'updated' | 'editing' | 'unchanged' {
    const base = this.base ?? incoming;
    const typing =
      parts.typing ||
      Object.entries(form.controls).some(
        ([field, control]) =>
          field in base && String(control.value ?? '') !== String(base[field] ?? ''),
      );
    const outcome = mergeSavedVersion(form, base, incoming);
    this.base = incoming;
    const updated = [...outcome.updated, ...parts.updated];
    const arrived = [...outcome.conflicts, ...parts.conflicts];
    if (updated.length === 0 && arrived.length === 0) return 'unchanged';
    this.updatedSignal.set(new Set(updated));
    const pending = this.conflictsSignal().filter(
      (conflict) => !arrived.some((next) => next.field === conflict.field),
    );
    this.conflictsSignal.set([...pending, ...arrived]);
    if (!typing) return 'updated';
    this.changedBySignal.set(actor ?? { id: '', name: null });
    return 'editing';
  }

  takeTheirs(form: DescriptorFormGroup, field: string): void {
    const conflict = this.conflictsSignal().find((candidate) => candidate.field === field);
    if (conflict === undefined) return;
    form.controls[field]?.setValue(conflict.theirs);
    this.forget(field);
  }

  keepMine(field: string): void {
    this.forget(field);
  }

  /** Drops what was typed and shows the saved version as it now stands. */
  reloadSaved(form: DescriptorFormGroup): void {
    if (this.base === null) return;
    for (const [field, control] of Object.entries(form.controls)) {
      if (field in this.base) control.setValue(this.base[field]);
    }
    form.markAsPristine();
    this.track(this.base);
  }

  protected forget(field: string): void {
    // The banner stays until reloaded or saved: it names who changed the record, not only what conflicts.
    this.conflictsSignal.update((conflicts) =>
      conflicts.filter((conflict) => conflict.field !== field),
    );
  }
}
