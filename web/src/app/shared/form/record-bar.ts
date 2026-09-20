// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';

/**
 * Saving a record from the bar beside its title (docs/SPEC.md § 7, 2026-09-19 23:18, design review finding 4,
 * measured: every long form's save sat below the first screen, the customer page at 3165 px with two of them).
 *
 * "Enregistrer" is the primary button and comes alive only once something changed — a save that is always
 * available teaches nothing about whether there is anything to save — beside "Annuler les modifications" and the
 * count of what is unsaved, which is the only thing on the page that says a form was left half-filled.
 */
@Component({
  selector: 'app-record-bar',
  imports: [MatButtonModule, MatIconModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="flex flex-wrap items-center gap-2" data-testid="record-bar">
      @if (changes() > 0) {
        <!-- Announced, because nothing else on the page says a form was left half-filled. -->
        <span
          role="status"
          class="text-sm text-on-surface-variant"
          data-testid="record-changes"
          [attr.data-count]="changes()"
        >
          {{
            (changes() === 1 ? 'form.one_unsaved' : 'form.unsaved')
              | translate: { count: changes() }
          }}
        </span>
        <button
          mat-button
          type="button"
          [disabled]="busy()"
          (click)="revert.emit()"
          data-testid="record-revert"
        >
          {{ 'form.revert' | translate }}
        </button>
      }
      <button
        mat-flat-button
        type="button"
        [disabled]="busy() || changes() === 0"
        (click)="save.emit()"
        data-testid="record-save"
      >
        <mat-icon aria-hidden="true">save</mat-icon>
        {{ saveLabel() | translate }}
      </button>
    </div>
  `,
})
export class RecordBar {
  /** How many fields hold something other than what was last saved; `dirtyCount` answers it. */
  readonly changes = input.required<number>();
  readonly busy = input(false);
  readonly saveLabel = input('form.save');

  readonly save = output<void>();
  readonly revert = output<void>();
}
