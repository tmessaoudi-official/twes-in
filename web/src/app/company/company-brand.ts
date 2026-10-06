// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { AuthFacade } from '../auth/auth-facade';
import { companyLogoUrl } from './company-api';

/**
 * The company a window facing customers belongs to: its logo and its name, the ones its documents carry. Read from the
 * signed-in state, which the customer screen's lock leaves open, as it does the logo itself.
 */
@Component({
  selector: 'app-company-brand',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (company(); as company) {
      <span class="flex min-w-0 items-center gap-3" data-testid="company-brand">
        @if (logo(); as url) {
          <img
            class="h-12 w-auto max-w-40 shrink-0 object-contain"
            [src]="url"
            alt=""
            data-testid="company-brand-logo"
          />
        }
        <span class="truncate text-2xl font-semibold" data-testid="company-brand-name">{{
          company.name
        }}</span>
      </span>
    }
  `,
})
export class CompanyBrand {
  private readonly auth = inject(AuthFacade);
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly logo = computed(() => {
    const company = this.company();
    return company?.logoVersion == null ? null : companyLogoUrl(company.id, company.logoVersion);
  });
}
