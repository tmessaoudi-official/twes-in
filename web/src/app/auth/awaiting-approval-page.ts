// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { CompanyClosingActions } from '../company/company-closing-actions';
import { CompanyFacade } from '../company/company-facade';
import { DayPipe, MomentPipe } from '../shared/i18n/format-pipes';
import { AuthFacade } from './auth-facade';
import { leaveOnceOpen } from './leave-once-open';
import { SignedOutLayout } from './signed-out-layout';

/**
 * Where a member of a company that is not open lands: pending an operator's approval, suspended or closed by one, or
 * shut until its subscription is settled. Outside the shell on purpose, since the API refuses its members everything in
 * that company and the shell could not load. It leaves by itself once the company opens, and offers the person's other
 * companies, which stay open to them.
 */
@Component({
  selector: 'app-awaiting-approval-page',
  imports: [SignedOutLayout, MatButtonModule, RouterLink, TranslatePipe, DayPipe, MomentPipe],
  templateUrl: './awaiting-approval-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AwaitingApprovalPage {
  private readonly auth = inject(AuthFacade);
  private readonly companyFacade = inject(CompanyFacade);
  private readonly router = inject(Router);

  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  /** Only the owner may declare a payment; anyone else is told whom to ask rather than meet a refusal. */
  protected readonly mayPay = computed(() => this.auth.hasPermission('subscription.pay'));
  protected readonly elsewhere = computed(() => {
    const current = this.company()?.id;
    return this.companyFacade.companies().filter((company) => company.id !== current);
  });
  protected readonly switching = signal(false);
  private readonly closing = inject(CompanyClosingActions);
  /** Whether the closing may still be taken back, read again with the session, which this page asks for every while. */
  protected readonly reopenable = computed(() => {
    const until = this.company()?.closing?.reopenUntil;
    return until !== undefined && Date.parse(until) > Date.now();
  });
  protected readonly reopening = signal(false);

  constructor() {
    void this.companyFacade.load();
    leaveOnceOpen();
  }

  protected async open(companyId: string): Promise<void> {
    this.switching.set(true);
    try {
      if (await this.companyFacade.switchTo(companyId)) await this.router.navigateByUrl('/');
    } finally {
      this.switching.set(false);
    }
  }

  /** Open again, the page leaves by itself (`leaveOnceOpen`). */
  protected async reopen(): Promise<void> {
    const company = this.company();
    if (company === null || this.reopening()) return;
    this.reopening.set(true);
    try {
      await this.closing.reopen({ id: company.id, name: company.name });
    } finally {
      this.reopening.set(false);
    }
  }

  protected async signOut(): Promise<void> {
    if (await this.auth.logout()) await this.router.navigateByUrl('/login');
  }
}
