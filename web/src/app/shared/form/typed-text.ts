// SPDX-License-Identifier: AGPL-3.0-or-later

import type { AbstractControl, ValidatorFn } from '@angular/forms';

/**
 * Every space (`\p{Z}`, the line breaks among `\s`) and every invisible format character (`\p{Cf}`: zero-width spaces,
 * byte-order and direction marks) at either end of a text: what the API's `TypedText::trimmed` takes. `String.trim()`
 * takes the spaces but not the zero-width or direction marks a paste from a page or an Arabic keyboard brings.
 */
const ENDS = /^[\s\0\p{Z}\p{Cf}]+|[\s\0\p{Z}\p{Cf}]+$/gu;

/** What a person typed or pasted, without what sits at its ends; what stands between them stays as typed. */
export function typedText(value: string): string {
  return value.replace(ENDS, '');
}

/**
 * A rule judging what was typed without its ends, as the API judges it (`TypedText::trimmed`): a descriptor form's
 * typed fields and a screen's own controls alike, so an address pasted with a direction mark at an end is not refused
 * for a character nobody can see.
 */
export function onTypedText(validator: ValidatorFn): ValidatorFn {
  return (control) =>
    typeof control.value === 'string'
      ? validator({ value: typedText(control.value) } as AbstractControl)
      : validator(control);
}
