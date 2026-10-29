// SPDX-License-Identifier: AGPL-3.0-or-later
import type { AbstractControl } from '@angular/forms';

import type { FieldError } from './form-builder';

/**
 * A value past its field's limit, kept whole and said: a field never cuts what a person typed or pasted, which would
 * drop its end unseen and save something else (row 239). Under the field it says the limit and how many characters are
 * there, and the save waits until it fits. `scripts/gates/pastes-kept-whole.sh` refuses a `maxlength` in a template.
 */
export function lengthSaid(value: string | null | undefined, max: number): FieldError | null {
  const length = value?.length ?? 0;
  return length > max ? said(length, max) : null;
}

/** The same for a control whose `Validators.maxLength` refused it. */
export function lengthSaidOf(control: AbstractControl): FieldError | null {
  // Null when the control has no error at all, undefined when it has others.
  const refused = control.getError('maxlength') as
    { requiredLength: number; actualLength: number } | null | undefined;
  return refused == null ? null : said(refused.actualLength, refused.requiredLength);
}

function said(length: number, max: number): FieldError {
  return { key: 'form.errors.max_length', params: { max }, count: `${length} / ${max}` };
}
