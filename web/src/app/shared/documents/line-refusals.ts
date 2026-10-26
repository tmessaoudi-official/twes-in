// SPDX-License-Identifier: AGPL-3.0-or-later

import type { AbstractControl, FormArray, FormGroup } from '@angular/forms';
import type { TranslateService } from '@ngx-translate/core';
import { type Subscription, take } from 'rxjs';
import type { FieldRefusal } from '../form/field-refusals';
import type { FieldError } from '../form/form-builder';
import { refusedError } from '../form/refusal-words';

/**
 * The line and field a document's refusal names, or null when it names the document itself. A rule writes
 * `lines[2].quantity` (or `lines.2.quantity`), a constraint `lines[2][quantity]`, and one of a line's taxes adds its
 * own place, `lines[2].taxComponentIds[1]`, which lands on the line's taxes.
 */
export function lineRefusal(path: string): { index: number; field: string } | null {
  const parts = path.replace(/\[([^\]]*)\]/g, '.$1').split('.');
  if (parts[0] !== 'lines' || parts.length < 3 || !/^\d+$/.test(parts[1])) return null;
  if (parts.length > 4 || (parts.length === 4 && !/^\d+$/.test(parts[3]))) return null;
  return { index: Number(parts[1]), field: parts[2] };
}

/** What the API refused a field for, while the field holds it; it outranks what the form finds wrong itself. */
export function refusedOn(control: AbstractControl): FieldError | null {
  return (control.getError('refused') as FieldError | null) ?? null;
}

/**
 * The refusals a document's lines editor says, the same in every editor: each under its line's field, held until the
 * next change to the lines, which lets the next save ask the API again. `changed` re-reads an OnPush template.
 */
export class LineRefusals {
  private cleared: Subscription | null = null;

  constructor(
    private readonly lines: () => FormArray<FormGroup>,
    private readonly translate: TranslateService,
    private readonly changed: () => void,
  ) {}

  /** Places each refusal of a line on its field and hands back the rest, the document's own. */
  show(refusals: readonly FieldRefusal[]): readonly FieldRefusal[] {
    const rest: FieldRefusal[] = [];
    let placed = false;
    for (const refusal of refusals) {
      const at = lineRefusal(refusal.field);
      const line = at === null ? undefined : this.lines().at(at.index);
      const control = at === null || line === undefined ? null : line.get([at.field]);
      if (control === null || control.disabled) {
        rest.push(refusal);
        continue;
      }
      control.setErrors({ refused: refusedError(this.translate, refusal) });
      control.markAsTouched();
      placed = true;
    }
    if (placed) {
      this.cleared?.unsubscribe();
      this.cleared = this.lines()
        .valueChanges.pipe(take(1))
        .subscribe(() => this.clear());
      this.changed();
    }
    return rest;
  }

  /** Drops what the API last refused on the lines. */
  clear(): void {
    this.cleared?.unsubscribe();
    this.cleared = null;
    for (const line of this.lines().controls) {
      for (const control of Object.values(line.controls)) {
        if (control.hasError('refused')) control.updateValueAndValidity();
      }
    }
    this.changed();
  }
}
