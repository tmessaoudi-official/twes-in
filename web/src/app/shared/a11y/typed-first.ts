// SPDX-License-Identifier: AGPL-3.0-or-later

import { afterNextRender, Directive, ElementRef, inject } from '@angular/core';

/**
 * Put on the field a page is opened to type into, outside the shell: the e-mail of the sign-in, a new password, the
 * code that confirms an authenticator. The cursor waits there as the field appears, also when the form is drawn only
 * once something was read, so what is typed first is not lost. It never takes the cursor from something holding it, a
 * field or a button just used. Inside the shell `RouteFocus` gives each new page's heading the cursor instead, so a
 * keyboard or screen-reader user reads the page first (RGAA 12.7).
 *
 * `autofocus` is no answer: browsers honour it once per document load, not for a field drawn by a later route.
 */
@Directive({ selector: '[appTypedFirst]' })
export class TypedFirst {
  constructor() {
    const host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
    afterNextRender(() => {
      const holder = host.ownerDocument.activeElement;
      if (holder !== null && holder !== host.ownerDocument.body) return;
      // The page stays where it opened: its explanation, or the QR code to scan, stays in view above the field.
      host.focus({ preventScroll: true });
    });
  }
}
