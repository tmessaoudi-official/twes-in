// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';

/** What the notice says, and how loudly. */
export interface SubscriptionNotice {
  readonly key: 'ending' | 'grace' | 'read_only';
  readonly days: number;
  readonly tone: 'warn' | 'error';
}

/** A trial or a paid period ending within this many days is worth saying. */
export const NOTICE_FROM_DAYS = 7;

/**
 * What the working company's subscription needs said, above every signed-in page (docs/SPEC.md § 7, 2026-09-17): the
 * days left before a trial or a paid period ends, the countdown once it has, and the state a company is left in when
 * nothing was settled. A locked company never reaches the shell, so it has no notice here.
 */
export function noticeFor(
  stage: 'trial' | 'paid' | 'grace' | 'unpaid' | null,
  daysLeft: number | null,
  access: 'full' | 'read_only' | 'locked',
): SubscriptionNotice | null {
  if ('read_only' === access) return { key: 'read_only', days: 0, tone: 'error' };
  if ('grace' === stage) return { key: 'grace', days: daysLeft ?? 0, tone: 'error' };
  if (
    ('trial' === stage || 'paid' === stage) &&
    daysLeft !== null &&
    daysLeft <= NOTICE_FROM_DAYS
  ) {
    return { key: 'ending', days: daysLeft, tone: 'warn' };
  }

  return null;
}

@Component({
  selector: 'app-subscription-notice',
  imports: [MatIconModule, TranslatePipe],
  templateUrl: './subscription-notice.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SubscriptionNoticeBar {
  private readonly auth = inject(AuthFacade);

  protected readonly notice = computed(() => {
    const subscription = this.auth.subscription();

    return noticeFor(
      subscription?.stage ?? null,
      subscription?.daysLeft ?? null,
      this.auth.access(),
    );
  });
}
