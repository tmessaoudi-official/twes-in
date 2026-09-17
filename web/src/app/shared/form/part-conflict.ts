// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { TranslatePipe } from '@ngx-translate/core';

/**
 * Beside something edited outside a form, such as a document's lines, when another person saved it too while it was
 * being edited here (docs/SPEC.md § 7, 2026-09-17): the saved version replaces all of it, or what is typed is kept.
 */
@Component({
  selector: 'app-part-conflict',
  imports: [MatButtonModule, TranslatePipe],
  template: `
    <div class="twes-field-conflict" [attr.data-testid]="'field-conflict-' + field()">
      <span>{{ message() | translate }}</span>
      <span class="flex flex-wrap gap-1">
        <button
          mat-button
          type="button"
          (click)="resolved.emit({ field: field(), choice: 'theirs' })"
          [attr.data-testid]="'field-take-theirs-' + field()"
        >
          {{ 'live.take_saved' | translate }}
        </button>
        <button
          mat-button
          type="button"
          (click)="resolved.emit({ field: field(), choice: 'mine' })"
          [attr.data-testid]="'field-keep-mine-' + field()"
        >
          {{ 'live.keep_typed' | translate }}
        </button>
      </span>
    </div>
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PartConflict {
  readonly field = input.required<string>();
  /** What changed on both sides, as a translation key. */
  readonly message = input.required<string>();
  readonly resolved = output<{ field: string; choice: 'theirs' | 'mine' }>();
}
