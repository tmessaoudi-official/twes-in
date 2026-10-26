// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DayPipe, MomentPipe } from '../shared/i18n/format-pipes';
import { deadlineClock } from '../shared/time/deadline-clock';
import { CompanyClosingActions } from './company-closing-actions';

/**
 * A company its owner closed is kept, not shut (docs/SPEC.md § 7, 2026-10-13 12:39): its members still read it and take
 * its books in the shell, whose screens offer only that, since the session holds their permissions to what the API
 * still grants. Above every page this says it is closed and until when it is kept, and offers its owner « Rouvrir »
 * while the days to take the closing back last; the texts are those the page outside the shell said it in.
 */
@Component({
  selector: 'app-closed-company-notice',
  imports: [MatButtonModule, MatIconModule, TranslatePipe, DayPipe, MomentPipe],
  template: `
    @if (closed(); as company) {
      <section
        class="bg-error-container text-on-error-container mx-auto flex w-full max-w-6xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2 text-sm sm:px-6 lg:px-8"
        data-testid="closed-notice"
      >
        <mat-icon aria-hidden="true" class="shrink-0">domain_disabled</mat-icon>
        <p>{{ 'auth.awaiting.closed' | translate: { company: company.name } }}</p>
        @if (company.closing; as closing) {
          <p data-testid="closed-notice-kept">
            {{ 'auth.awaiting.closed_kept' | translate: { day: closing.retainUntil | day } }}
          </p>
          <!-- The closing is taken back by its owner alone, until the day the API set; then it is final. -->
          @if (reopenable()) {
            @if (company.role === 'owner') {
              <p data-testid="closed-notice-reopen-until">
                {{
                  'auth.awaiting.reopen_until' | translate: { moment: closing.reopenUntil | moment }
                }}
              </p>
              <button
                mat-stroked-button
                type="button"
                (click)="reopen()"
                [disabled]="reopening()"
                data-testid="closed-notice-reopen"
              >
                {{ 'auth.awaiting.reopen' | translate }}
              </button>
            } @else {
              <p data-testid="closed-notice-ask-owner">
                {{
                  'auth.awaiting.reopen_ask_owner'
                    | translate: { moment: closing.reopenUntil | moment }
                }}
              </p>
            }
          }
        }
      </section>
    }
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ClosedCompanyNotice {
  private readonly auth = inject(AuthFacade);
  private readonly closing = inject(CompanyClosingActions);

  protected readonly closed = computed(() =>
    this.auth.companyClosedDown() ? (this.auth.me()?.company ?? null) : null,
  );
  /** Moves on when the days to reopen run out, which nothing announces to a page left open. */
  private readonly now = deadlineClock(() => [this.closed()?.closing?.reopenUntil ?? null]);
  /** Whether the closing may still be taken back, read again with the session and when its last moment passes. */
  protected readonly reopenable = computed(() => {
    const until = this.closed()?.closing?.reopenUntil;
    return until !== undefined && Date.parse(until) > this.now();
  });
  protected readonly reopening = signal(false);

  protected async reopen(): Promise<void> {
    const company = this.closed();
    if (company === null || this.reopening()) return;
    this.reopening.set(true);
    try {
      await this.closing.reopen({ id: company.id, name: company.name });
    } finally {
      this.reopening.set(false);
    }
  }
}
