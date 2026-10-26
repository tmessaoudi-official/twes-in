// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { leaveOnceOpen } from '../auth/leave-once-open';
import { SignedOutLayout } from '../auth/signed-out-layout';
import { AccountPage } from './account-page';

/**
 * « Mon compte » for a member of a company that is shut (pending, suspended, or locked by its subscription), outside the
 * shell, which could not load: the password, the second factor, the devices and deleting the account are the person's
 * own, which no company's state may hold back. Only « Sécurité » shows (`AccountPage.tabs`); the page goes back to the
 * waiting page, and into the shell's own « Mon compte » once the company opens.
 */
@Component({
  selector: 'app-closed-account-page',
  imports: [SignedOutLayout, AccountPage, MatButtonModule, RouterLink, TranslatePipe],
  template: `
    <app-signed-out-layout plain>
      <app-account-page />
      <div twesAuthAfter>
        <a mat-stroked-button routerLink="/awaiting-approval" data-testid="closed-account-back">
          {{ 'legal.back' | translate }}
        </a>
      </div>
    </app-signed-out-layout>
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ClosedAccountPage {
  constructor() {
    leaveOnceOpen('/account');
  }
}
