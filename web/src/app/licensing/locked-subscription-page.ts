// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { SignedOutLayout } from '../auth/signed-out-layout';
import { SubscriptionPage } from './subscription-page';

/**
 * The subscription page for a company its subscription has locked. Such a company never reaches the shell — the API
 * refuses its members everything else — so the one thing it is still allowed, declaring a payment, is served outside
 * the shell here (docs/SPEC.md § 7, 2026-09-17). Locking a company that cannot then say it paid would be a dead end.
 */
@Component({
  selector: 'app-locked-subscription-page',
  imports: [SignedOutLayout, SubscriptionPage, MatButtonModule, TranslatePipe],
  template: `
    <app-signed-out-layout plain>
      <app-subscription-page />
      <div>
        <button mat-stroked-button type="button" (click)="signOut()" data-testid="locked-sign-out">
          {{ 'auth.awaiting.sign_out' | translate }}
        </button>
      </div>
    </app-signed-out-layout>
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class LockedSubscriptionPage {
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);

  protected async signOut(): Promise<void> {
    await this.auth.logout();
    await this.router.navigateByUrl('/login');
  }
}
