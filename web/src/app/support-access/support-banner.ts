// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { FormatFacade } from '../shared/i18n/format-facade';

/**
 * While the owner lets the platform's support read the company, every page says so and until when: to the operator
 * reading it, that nothing they do here is kept, and to its members, that someone outside the company may be looking,
 * with the way to close it for the owner. A state of the company, so a status line, not a toast that leaves.
 */
@Component({
  selector: 'app-support-banner',
  imports: [MatIconModule, RouterLink, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (until(); as end) {
      <p
        role="status"
        class="m-0 mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-outline-variant bg-surface-container-high px-4 py-2 text-on-surface"
        data-testid="support-banner"
      >
        <mat-icon aria-hidden="true">support_agent</mat-icon>
        @if (reading()) {
          <span>{{
            'support_access.banner_support' | translate: { company: company(), until: end }
          }}</span>
        } @else {
          <span>{{ 'support_access.banner_members' | translate: { until: end } }}</span>
          @if (owner()) {
            <a
              routerLink="/company/support-access"
              class="font-medium text-primary"
              data-testid="support-banner-manage"
              >{{ 'support_access.manage' | translate }}</a
            >
          }
        }
      </p>
    }
  `,
})
export class SupportBanner {
  private readonly auth = inject(AuthFacade);
  private readonly format = inject(FormatFacade);

  protected readonly reading = computed(() => this.auth.me()?.company?.role === 'support');
  protected readonly owner = computed(() => this.auth.me()?.company?.role === 'owner');
  protected readonly company = computed(() => this.auth.me()?.company?.name ?? '');
  protected readonly until = computed(() => {
    const until = this.auth.me()?.supportAccessUntil ?? null;
    return until === null ? null : this.format.moment(until, this.auth.me()?.company?.timezone);
  });
}
