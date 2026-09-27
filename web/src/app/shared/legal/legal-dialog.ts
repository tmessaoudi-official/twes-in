// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { TranslatePipe } from '@ngx-translate/core';
import { LEGAL_ROUTE } from './legal-pages';
import { LegalText } from './legal-text';

export interface LegalDialogData {
  slug: string;
}

/**
 * A legal text read over the screen it was opened from: closing it leaves that screen exactly as it was, what was
 * typed and not yet saved included, which leaving for the page would lose.
 */
@Component({
  selector: 'app-legal-dialog',
  imports: [LegalText, MatButtonModule, MatDialogModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="legal-title">{{ 'legal.pages.' + slug | translate }}</h2>
    <mat-dialog-content>
      <app-legal-text [slug]="slug" />
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <!-- A tab of its own, so this screen stays as it is behind the panel. -->
      <a mat-button [href]="page" target="_blank" rel="noopener" data-testid="legal-as-page">{{
        'legal.as_page' | translate
      }}</a>
      <button mat-flat-button type="button" (click)="dialog.close()" data-testid="legal-close">
        {{ 'legal.close' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class LegalDialog {
  protected readonly dialog = inject(MatDialogRef<LegalDialog>);
  protected readonly slug = inject<LegalDialogData>(MAT_DIALOG_DATA).slug;
  protected readonly page = `${LEGAL_ROUTE}/${this.slug}`;
}
