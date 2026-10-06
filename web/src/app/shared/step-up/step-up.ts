// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { MatDialog } from '@angular/material/dialog';
import { firstValueFrom } from 'rxjs';
import { StepUpDialog, type StepUpReason } from './step-up-dialog';

/**
 * Asks the signed-in person to prove who they are again, before something a stranger at the same screen must not be
 * able to do (docs/SPEC.md § 7). Leaving customer view is the first, then exporting a list (audit H-b2): a customer looking at the screen would otherwise
 * only have to press the button. The answer is only yes or no, and each yes pays for the one action that asked.
 */
@Injectable({ providedIn: 'root' })
export class StepUp {
  private readonly dialog = inject(MatDialog);

  /**
   * True once the password or a passkey was confirmed; false when the person gave up. `intro` is the translation key
   * of the sentence saying why; leaving customer view's is the default.
   */
  async request(intro = 'step_up.intro'): Promise<boolean> {
    const ref = this.dialog.open<StepUpDialog, StepUpReason, boolean>(StepUpDialog, {
      data: { intro },
      autoFocus: 'first-tabbable',
      disableClose: false,
    });
    return (await firstValueFrom(ref.afterClosed())) === true;
  }
}
