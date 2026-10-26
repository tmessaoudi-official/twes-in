// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, type OnInit, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { QrCode } from '../qr/qr-code';
import { PhonePairing } from './phone-pairing';

/**
 * Lends a phone to this tab as a scanner (docs/SPEC.md § 7, 2026-09-23 09:45, slice 4): the link as a QR code for the
 * phone's camera and as an address to type, whether the phone is there yet, and the way to let it go. Closing the
 * dialog keeps the phone scanning; letting it go, leaving or signing out ends it. A code no phone opened in time is
 * said to have lapsed, with a new one a press away.
 */
@Component({
  selector: 'app-phone-pairing-dialog',
  imports: [MatButtonModule, MatDialogModule, MatIconModule, TranslatePipe, QrCode],
  templateUrl: './phone-pairing-dialog.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PhonePairingDialog implements OnInit {
  protected readonly pairing = inject(PhonePairing);
  private readonly dialog = inject(MatDialogRef<PhonePairingDialog>);
  /** A new code is being asked for: one press asks once. */
  protected readonly opening = signal(false);

  /** Opened again, it shows a code a phone can still claim: the one held, or a new one when it lapsed. */
  async ngOnInit(): Promise<void> {
    await this.pairing.ensure();
  }

  protected async renew(): Promise<void> {
    if (this.opening()) return;
    this.opening.set(true);
    try {
      await this.pairing.open();
    } finally {
      this.opening.set(false);
    }
  }

  protected end(): void {
    this.pairing.end();
    this.dialog.close();
  }
}
