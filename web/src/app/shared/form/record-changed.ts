// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import type { ChangedBy } from './record-sync';

/**
 * Above an editor, while something is typed in it and another person saved the same record (docs/SPEC.md § 7,
 * 2026-09-17): who did, how many fields need a choice, and a way back to the saved version. Nothing typed is lost
 * unless the person reloads; saving keeps what is typed, the last save winning.
 */
@Component({
  selector: 'app-record-changed',
  imports: [MatButtonModule, MatIconModule, TranslatePipe],
  template: `
    <div class="twes-record-changed" role="status" data-testid="record-changed">
      <mat-icon aria-hidden="true">sync_problem</mat-icon>
      <div class="flex min-w-0 flex-1 flex-col gap-0.5">
        <span>
          @if (changedBy().name; as name) {
            {{ 'live.changed_by' | translate: { name } }}
          } @else {
            {{ 'live.changed_by_someone' | translate }}
          }
        </span>
        <span class="text-sm opacity-80">
          @if (conflictCount() > 0) {
            {{ 'live.conflicts' | translate: { count: conflictCount() } }}
          } @else {
            {{ 'live.merged' | translate }}
          }
        </span>
      </div>
      <button mat-button type="button" (click)="reload.emit()" data-testid="record-reload">
        {{ 'live.reload' | translate }}
      </button>
    </div>
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RecordChanged {
  readonly changedBy = input.required<ChangedBy>();
  readonly conflictCount = input(0);
  readonly reload = output<void>();
}
