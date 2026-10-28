// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { MatDialog } from '@angular/material/dialog';
import { Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import type { ActionConfirm } from '../shared/actions/screen-action';
import { Feedback } from '../shared/feedback/feedback';
import { TextsParts } from '../shared/i18n/texts-part';
import { ConfirmDialog } from '../shared/ui/confirm-dialog';
import { ConnectedDevicesApi } from './connected-devices-api';

/** The person signs in again where a session ended: corrected afterwards, never undone. */
const ASK: ActionConfirm = {
  kind: 'corrigeable',
  title: 'account.devices.end_others_title',
  message: 'account.devices.end_others_message',
  confirmLabel: 'account.devices.end_others',
  keepLabel: 'account.devices.keep',
};

/**
 * « Déconnecter tous les autres », the same wherever a person's own account appears (« Mon compte › Appareils
 * connectés », an operator's own row on the platform's accounts): every session but this tab ends, and since whoever
 * else used the account may know its password, the question says a new password keeps them out and the toast offers
 * the way to change it (docs/SPEC.md § 7, 2026-10-27 21:43). Opens its own texts, as a screen not of « Mon compte »
 * asks it too.
 */
@Injectable({ providedIn: 'root' })
export class EndOtherSessions {
  private readonly api = inject(ConnectedDevicesApi);
  private readonly dialog = inject(MatDialog);
  private readonly feedback = inject(Feedback);
  private readonly router = inject(Router);
  private readonly texts = inject(TextsParts);

  /** How many sessions ended, or null when the person kept them or ending them failed (said). */
  async run(): Promise<number | null> {
    await this.texts.open('account').catch(() => undefined);
    const confirmed = await firstValueFrom(
      this.dialog
        .open<ConfirmDialog, ActionConfirm, boolean>(ConfirmDialog, {
          data: ASK,
          autoFocus: 'dialog',
        })
        .afterClosed(),
    );
    if (confirmed !== true) return null;
    let ended: number;
    try {
      ended = await this.api.endOthers();
    } catch {
      this.feedback.failure('account.devices.failed');
      return null;
    }
    this.feedback.effect('account.devices.others_ended', { count: ended }, ASK.kind, {
      key: 'account.devices.change_password',
      run: () => void this.router.navigate(['/account'], { queryParams: { tab: 'security' } }),
    });
    return ended;
  }
}
