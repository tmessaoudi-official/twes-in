// SPDX-License-Identifier: AGPL-3.0-or-later

import { Directive, effect, inject, input } from '@angular/core';
import { MatInput } from '@angular/material/input';

/**
 * A field the screen holds without a form control (a signal typed into, a number checked against its bounds, what the
 * API last refused) refused the way a form's field is: Material's invalid state, so its outline and label turn red,
 * `aria-invalid` is set and its `<mat-error>` shows in the hint's place. Material reads that state from a form control
 * alone; here the screen says it, and the field asks Material to read it again whenever it changes.
 */
@Directive({ selector: 'input[matInput][appRefused], textarea[matInput][appRefused]' })
export class RefusedField {
  /** Whether the value now in the field is refused, by the screen's own rule or by the API's last answer. */
  readonly appRefused = input.required<boolean>();

  constructor() {
    const field = inject(MatInput);
    field.errorStateMatcher = { isErrorState: () => this.appRefused() };
    effect(() => {
      this.appRefused();
      field.updateErrorState();
    });
  }
}
