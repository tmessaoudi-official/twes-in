// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, DestroyRef, inject } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { CompanyFacade } from '../company/company-facade';
import { Feedback } from '../shared/feedback/feedback';
import { FormatFacade } from '../shared/i18n/format-facade';
import { LiveChanges } from '../shared/realtime/live-changes';

/**
 * While the owner lets the platform's support read the company, every page says so and until when: to the operator
 * reading it, that nothing they do here is kept, and to its members, that someone outside the company may be looking,
 * with the way to close it for the owner. A state of the company, so a status line, not a toast that leaves. The
 * operator leaves from it, back to the platform: done reading, nothing they open after is journalled for the owner.
 * When the owner closes it while the operator reads, the banner says so and takes them back there, rather than leaving
 * the open page to answer « introuvable ».
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
          <button
            type="button"
            class="font-medium text-primary"
            (click)="leave()"
            [disabled]="companies.switching()"
            data-testid="support-banner-leave"
          >
            {{ 'support_access.leave' | translate }}
          </button>
          @if (reason(); as said) {
            <span class="basis-full" data-testid="support-banner-reason"
              >{{ 'support_access.reason_said' | translate }} <bdi>{{ said }}</bdi></span
            >
          }
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
  private readonly router = inject(Router);
  protected readonly companies = inject(CompanyFacade);
  private readonly feedback = inject(Feedback);

  protected readonly reading = computed(() => this.auth.me()?.company?.role === 'support');
  protected readonly owner = computed(() => this.auth.me()?.company?.role === 'owner');
  protected readonly company = computed(() => this.auth.me()?.company?.name ?? '');
  /** What the owner said is wrong, which the API gives only to the support reading the company. */
  protected readonly reason = computed(() => this.auth.me()?.supportAccessReason ?? null);
  protected readonly until = computed(() => {
    const until = this.auth.me()?.supportAccessUntil ?? null;
    return until === null ? null : this.format.moment(until, this.auth.me()?.company?.timezone);
  });

  constructor() {
    // The operator hears every grant on the platform's channel; the session read again says whether this one is gone.
    inject(LiveChanges).on(['support_grant'], () => void this.grantChanged(), inject(DestroyRef));
  }

  protected async leave(): Promise<void> {
    if (await this.companies.leave()) await this.router.navigateByUrl('/platform');
  }

  /** Granted again, or heard by a member, it says nothing; closed while the operator reads, it says so once. */
  private async grantChanged(): Promise<void> {
    const reading = this.auth.me()?.company ?? null;
    if (reading?.role !== 'support') return;
    await this.auth.refresh();
    if (this.auth.me()?.company?.id === reading.id) return;
    this.feedback.notice('support_access.closed_by_owner', { company: reading.name });
    await this.router.navigateByUrl('/platform');
  }
}
