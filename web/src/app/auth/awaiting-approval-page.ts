// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from './auth-facade';

/**
 * Where a member of a company that is not active lands: pending an operator's approval, or suspended by one. Outside
 * the shell on purpose, since the API refuses its members everything in that company and the shell could not load.
 */
@Component({
  selector: 'app-awaiting-approval-page',
  imports: [MatButtonModule, TranslatePipe],
  templateUrl: './awaiting-approval-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AwaitingApprovalPage {
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);

  protected readonly company = computed(() => this.auth.me()?.company ?? null);

  protected async signOut(): Promise<void> {
    await this.auth.logout();
    await this.router.navigateByUrl('/login');
  }
}
