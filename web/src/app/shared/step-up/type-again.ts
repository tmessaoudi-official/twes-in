// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ElementRef } from '@angular/core';

/**
 * A proof refused leaves the cursor on the button pressed, so what is typed next goes nowhere. The answer refused takes
 * the cursor back, selected, so it is typed again over itself without a click first.
 */
export function typeAgain(field: ElementRef<HTMLInputElement> | undefined): void {
  field?.nativeElement.focus();
  field?.nativeElement.select();
}
