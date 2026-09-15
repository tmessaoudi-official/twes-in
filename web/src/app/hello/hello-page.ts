// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';

/** The signed-in home page: who you are, where you work, what you may do. The shell carries the chrome around it. */
@Component({
  selector: 'app-hello-page',
  imports: [TranslatePipe, RouterLink, MatButtonModule],
  templateUrl: './hello-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class HelloPage {
  protected readonly me = inject(AuthFacade).me;
}
