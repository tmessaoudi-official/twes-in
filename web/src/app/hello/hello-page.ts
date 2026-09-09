// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatToolbarModule } from '@angular/material/toolbar';
import { Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';

/** The signed-in landing page of G1a: who you are, where you work, what you may do, and the way out. */
@Component({
  selector: 'app-hello-page',
  imports: [MatToolbarModule, MatButtonModule, TranslatePipe],
  templateUrl: './hello-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class HelloPage {
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);

  protected readonly me = this.auth.me;
  protected readonly signingOut = signal(false);

  protected async logout(): Promise<void> {
    this.signingOut.set(true);
    try {
      await this.auth.logout();
    } finally {
      this.signingOut.set(false);
      await this.router.navigateByUrl('/login');
    }
  }
}
