// SPDX-License-Identifier: AGPL-3.0-or-later

import type { AbstractControl } from '@angular/forms';

/** What a person types in: the first of them a wrong value is in takes the cursor. */
const INVALID_FIELD = [
  'input.ng-invalid',
  'textarea.ng-invalid',
  'select.ng-invalid',
  'mat-select.ng-invalid',
].join(', ');

/**
 * A form written by hand refuses the way a descriptor form does (`DescriptorForm`): its button stays active, and
 * pressing it while a value is wrong shows every field's error under it and puts the cursor in the first one, where a
 * greyed-out button said nothing of what to change. Several groups (a record and its lines) are said together. Returns
 * whether something was wrong, so the caller stops there.
 */
export function revealInvalid(
  controls: AbstractControl | readonly AbstractControl[],
  host: HTMLElement,
): boolean {
  const wrong = (Array.isArray(controls) ? controls : [controls]).filter(
    (control: AbstractControl) => control.invalid,
  );
  if (wrong.length === 0) return false;
  for (const control of wrong) control.markAllAsTouched();
  host.querySelector<HTMLElement>(INVALID_FIELD)?.focus();
  return true;
}
