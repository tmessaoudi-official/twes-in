// SPDX-License-Identifier: AGPL-3.0-or-later

import { Directive, inject, input } from '@angular/core';
import { MatDialog } from '@angular/material/dialog';
import { LegalDialog, type LegalDialogData } from './legal-dialog';
import { LEGAL_ROUTE } from './legal-pages';

/**
 * A link to a legal page that opens its text in a panel over the current screen, so the person comes back exactly
 * where they were. It stays a real link: a click asking for a new tab or window is left to the browser.
 */
@Directive({
  selector: 'a[appLegalLink]',
  host: {
    '[attr.href]': 'href()',
    'aria-haspopup': 'dialog',
    '(click)': 'open($event)',
  },
})
export class LegalLink {
  readonly appLegalLink = input.required<string>();
  private readonly dialog = inject(MatDialog);

  protected href(): string {
    return `${LEGAL_ROUTE}/${this.appLegalLink()}`;
  }

  protected open(event: MouseEvent): void {
    if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
      return;
    }
    event.preventDefault();
    this.dialog.open<LegalDialog, LegalDialogData>(LegalDialog, {
      data: { slug: this.appLegalLink() },
      width: '48rem',
      maxWidth: 'calc(100vw - 2rem)',
      autoFocus: 'dialog',
    });
  }
}
