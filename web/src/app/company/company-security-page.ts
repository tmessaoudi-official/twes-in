// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  OnInit,
} from '@angular/core';
import { LiveChanges } from '../shared/realtime/live-changes';
import { MatSlideToggleChange, MatSlideToggleModule } from '@angular/material/slide-toggle';
import { Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { CompanySecurityFacade } from './company-security-facade';
import { Feedback } from '../shared/feedback/feedback';

/**
 * The company's sign-in requirements: one switch, whether every member needs a second factor. Turning it on while
 * this very account has none sends it to set one up, since nothing else will work for it until then.
 */
@Component({
  selector: 'app-company-security-page',
  imports: [MatSlideToggleModule, TranslatePipe],
  templateUrl: './company-security-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CompanySecurityPage implements OnInit {
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  private readonly facade = inject(CompanySecurityFacade);
  private readonly feedback = inject(Feedback);
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);

  protected readonly security = this.facade.security;
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      this.live.reloadOn(['company'], () => this.facade.load(companyId), this.destroyRef);
      await this.facade.load(companyId);
    }
  }

  protected async toggle(change: MatSlideToggleChange): Promise<void> {
    const companyId = this.company()?.id;
    const current = this.security()?.mfaRequired ?? false;
    if (!companyId || this.busy()) {
      change.source.checked = current;
      return;
    }
    if (!(await this.facade.requireSecondFactor(companyId, change.checked))) {
      change.source.checked = current;
      return;
    }
    if (this.auth.needsEnrolment()) {
      await this.router.navigateByUrl('/two-factor');
      return;
    }
    this.feedback.success('company.security.saved');
  }
}
