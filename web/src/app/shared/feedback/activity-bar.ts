// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { TranslatePipe } from '@ngx-translate/core';
import { RequestActivity } from './request-activity';

/**
 * What the application is waiting for, at the top of every page, signed in or not (docs/SPEC.md § 8 row 48): a thin
 * bar while it works, a line when that is slow, and a notice when the browser is offline or the service cannot be
 * reached, with when it tries again and a way to try now.
 */
@Component({
  selector: 'app-activity-bar',
  imports: [MatButtonModule, MatIconModule, MatProgressBarModule, TranslatePipe],
  template: `
    @if (activity.busy()) {
      <mat-progress-bar
        mode="indeterminate"
        class="twes-activity-progress"
        [attr.aria-label]="'feedback.loading' | translate"
        data-testid="activity-progress"
      />
    }
    @if (activity.offline()) {
      <div class="twes-activity-notice" role="alert" data-testid="activity-offline">
        <mat-icon aria-hidden="true">wifi_off</mat-icon>
        <span>{{ 'feedback.offline' | translate }}</span>
      </div>
    } @else if (activity.unavailable()) {
      <div class="twes-activity-notice" role="alert" data-testid="activity-unavailable">
        <mat-icon aria-hidden="true">cloud_off</mat-icon>
        <span>{{ 'feedback.unavailable' | translate: { seconds: activity.retryIn() } }}</span>
        <button mat-button type="button" (click)="activity.retryNow()" data-testid="activity-retry">
          {{ 'feedback.retry' | translate }}
        </button>
      </div>
    } @else if (activity.slow()) {
      <div class="twes-activity-notice" role="status" data-testid="activity-slow">
        <mat-icon aria-hidden="true">hourglass_top</mat-icon>
        <span>{{ 'feedback.slow' | translate }}</span>
      </div>
    }
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ActivityBar {
  protected readonly activity = inject(RequestActivity);
}
