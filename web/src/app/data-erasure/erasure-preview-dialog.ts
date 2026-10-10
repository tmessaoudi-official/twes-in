// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';

/** One line of the dry run: a part, and what it would take, already said in words. */
export interface ErasurePreviewLine {
  readonly label: string;
  readonly summary: string;
}

/**
 * « Ce qui sera effacé »: the dry run every bulk action gets, counted the moment it opens, and what kind of action it
 * is. Answers true for « Effacer ».
 */
@Component({
  selector: 'app-erasure-preview-dialog',
  imports: [MatButtonModule, MatDialogModule, MatIconModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>{{ 'data_erasure.preview.title' | translate }}</h2>
    <mat-dialog-content>
      <ul class="m-0 flex list-none flex-col gap-2 p-0" data-testid="erasure-preview-lines">
        @for (line of lines; track line.label) {
          <li class="flex justify-between gap-4">
            <span>{{ line.label }}</span>
            <span class="font-medium">{{ line.summary }}</span>
          </li>
        }
      </ul>
      <p class="mt-3">{{ 'data_erasure.preview.counted' | translate }}</p>
      <p class="mt-3 flex items-start gap-2 text-sm">
        <mat-icon aria-hidden="true" class="shrink-0">undo</mat-icon>
        <span>
          <strong>{{ 'actions.kind.annulable' | translate }}</strong>
          · {{ 'data_erasure.preview.undoable' | translate }}
        </span>
      </p>
      <p class="mt-3 text-sm text-on-surface-variant">
        {{ 'data_erasure.preview.proof' | translate }}
      </p>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button
        mat-button
        type="button"
        [mat-dialog-close]="false"
        data-testid="erasure-preview-back"
      >
        {{ 'data_erasure.preview.back' | translate }}
      </button>
      <button
        mat-flat-button
        type="button"
        [mat-dialog-close]="true"
        data-testid="erasure-preview-erase"
      >
        {{ 'data_erasure.preview.erase' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class ErasurePreviewDialog {
  protected readonly lines = inject<readonly ErasurePreviewLine[]>(MAT_DIALOG_DATA);
}
