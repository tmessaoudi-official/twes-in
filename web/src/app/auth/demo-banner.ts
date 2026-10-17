// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from './auth-facade';

/**
 * While a visitor tries the demo, every page says it is one: a made-up company, no mail that leaves, no document with
 * fiscal value, and nothing kept past signing out. A state of the session, so a status line, not a toast that leaves.
 */
@Component({
  selector: 'app-demo-banner',
  imports: [MatIconModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (demo()) {
      <p
        role="status"
        class="m-0 mb-4 flex items-center gap-2 rounded-lg border border-outline-variant bg-surface-container-high px-4 py-2 text-on-surface"
        data-testid="demo-banner"
      >
        <mat-icon aria-hidden="true">storefront</mat-icon>
        <span>{{ 'auth.demo_banner' | translate }}</span>
      </p>
    }
  `,
})
export class DemoBanner {
  private readonly auth = inject(AuthFacade);
  protected readonly demo = computed(() => this.auth.me()?.user.demo === true);
}
