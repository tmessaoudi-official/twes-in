// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { CompanyClosingActions } from '../company/company-closing-actions';
import { CompanyFacade } from '../company/company-facade';
import { AuthFacade } from './auth-facade';
import { leaveOnceOpen } from './leave-once-open';
import { SignedOutLayout } from './signed-out-layout';
import { supportChannels } from './support-channels';

/**
 * Where a member of a company that is not open lands: pending an operator's approval, suspended by one, or shut
 * until its subscription is settled; a company its owner closed is read in the shell instead (`ClosedCompanyNotice`). Outside the shell on purpose, since the API refuses its members everything in
 * that company and the shell could not load. It leaves by itself once the company opens, and offers the person's other
 * companies, which stay open to them, their own account, how to reach the platform's operators, and to the owner of a
 * company its subscription locked, closing it down: an unpaid subscription keeps them from working, never from stopping.
 */
@Component({
  selector: 'app-awaiting-approval-page',
  imports: [SignedOutLayout, MatButtonModule, MatIconModule, RouterLink, TranslatePipe],
  templateUrl: './awaiting-approval-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AwaitingApprovalPage {
  private readonly auth = inject(AuthFacade);
  private readonly companyFacade = inject(CompanyFacade);
  private readonly router = inject(Router);
  private readonly closing = inject(CompanyClosingActions);

  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  /** Only the owner may declare a payment; anyone else is told whom to ask rather than meet a refusal. */
  protected readonly mayPay = computed(() => this.auth.hasPermission('subscription.pay'));
  /**
   * The owner of a working company its subscription locked closes it down from here, as the API allows through the
   * lock; a company pending or suspended is the operator's to close.
   */
  protected readonly mayClose = computed(() => {
    const company = this.company();
    return company?.status === 'active' && company.role === 'owner';
  });
  protected readonly channels = computed(() => supportChannels(this.auth.me()?.support));
  protected readonly closingDown = signal(false);
  protected readonly elsewhere = computed(() => {
    const current = this.company()?.id;
    return this.companyFacade.companies().filter((company) => company.id !== current);
  });
  protected readonly switching = signal(false);

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

  /** Closed, the session follows and the page leaves for the shell, where the closed company is read and reopened. */
  protected async closeDown(): Promise<void> {
    const company = this.company();
    if (company === null || this.closingDown()) return;
    this.closingDown.set(true);
    try {
      await this.closing.close({ id: company.id, name: company.name });
    } finally {
      this.closingDown.set(false);
    }
  }

  protected async signOut(): Promise<void> {
    if (await this.auth.logout()) await this.router.navigateByUrl('/login');
  }
}
