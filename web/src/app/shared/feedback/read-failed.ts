// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { MatButton } from '@angular/material/button';
import { TranslatePipe } from '@ngx-translate/core';

/**
 * What a page says when something it shows could not be read: the line in the page's own words, and « Réessayer »,
 * which hands the page `retry` to read as it did on opening. The one way a page offers to read again outside a list
 * (DataList says it in place of its rows) and a home panel, so it looks and works the same on every screen.
 *
 * A facade whose saves share the line with its reads passes `retryable` its `readFailed()`: under a refused save the
 * line stays, and « Réessayer » does not, since reading again would not undo the refusal.
 */
@Component({
  selector: 'app-read-failed',
  imports: [MatButton, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'flex flex-wrap items-center gap-x-2' },
  template: `
    <div role="alert" class="text-error {{ alertClass() }}" [attr.data-testid]="testId()">
      <ng-content />
    </div>
    @if (retryable()) {
      <button mat-button type="button" [attr.data-testid]="retryTestId()" (click)="retry.emit()">
        {{ 'feedback.retry' | translate }}
      </button>
    }
  `,
})
export class ReadFailed {
  /** The line's test id, kept from the page's own line. */
  readonly testId = input.required<string>();
  readonly retryTestId = input.required<string>();
  /** The page's own layout of the line, such as the refused fields listed under it. */
  readonly alertClass = input('');
  /** False while the line says a refused save rather than a failed read. */
  readonly retryable = input(true);
  readonly retry = output<void>();
}
