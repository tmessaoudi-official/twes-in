// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { TranslatePipe } from '@ngx-translate/core';
import { Select } from '../form/select';
import { LanguageFacade, SUPPORTED_LANGUAGES } from './language-facade';
import { languageChoices } from './language-options';

/**
 * The interface language, chosen from the account menu: a menu cannot hold the dropdown, whose list must stay
 * searchable however many languages ship, so the menu opens this. A choice applies at once and closes it.
 */
@Component({
  selector: 'app-language-dialog',
  imports: [FormsModule, MatButtonModule, MatDialogModule, Select, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title id="language-dialog-title">{{ 'shell.language' | translate }}</h2>
    <mat-dialog-content>
      <app-select
        class="mt-2"
        labelledBy="language-dialog-title"
        [languages]="true"
        [options]="options"
        [ngModel]="language.current()"
        (ngModelChange)="choose($event)"
        testId="language-dialog"
      />
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="dialog.close()" data-testid="language-dialog-close">
        {{ 'shell.language_close' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class LanguageDialog {
  protected readonly dialog = inject(MatDialogRef<LanguageDialog>);
  protected readonly language = inject(LanguageFacade);
  protected readonly options = languageChoices(SUPPORTED_LANGUAGES, 'language-dialog');

  protected async choose(tag: string | null): Promise<void> {
    if (tag === null || tag === this.language.current()) {
      return;
    }
    this.dialog.close();
    await this.language.use(tag);
  }
}
