// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { type AbstractControl, FormControl, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe } from '@ngx-translate/core';
import type { TextOutcome } from './texts-facade';
import type { SavedText, TextRefusalCode } from './texts-types';

export interface TextDialogData {
  readonly language: string;
  readonly original: string;
  /** The installation's wording, shown on a company's page where the installation gave one. */
  readonly installation: string | null;
  /** What the field starts with: the level's own wording, else what the screens show now. */
  readonly value: string;
  /** The marks the text keeps, `{{name}}` or `%name%`. */
  readonly marks: readonly string[];
  readonly save: (value: string) => Promise<TextOutcome<SavedText>>;
}

/**
 * One text worded otherwise, in one language, with the original beside the field: what is refused is said under the
 * field and the text stays as typed, so it can be put right. Answers with what was kept, or null when closed.
 */
@Component({
  selector: 'app-text-dialog',
  imports: [
    MatButtonModule,
    MatDialogModule,
    MatFormFieldModule,
    MatInputModule,
    ReactiveFormsModule,
    TranslatePipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="text-dialog-title">
      {{ 'texts.dialog.title' | translate }}
    </h2>
    <mat-dialog-content>
      <div class="flex flex-col gap-3">
        <div class="flex flex-col gap-1">
          <span class="text-sm font-medium">{{ 'texts.dialog.original' | translate }}</span>
          <p
            class="twes-value whitespace-pre-wrap"
            dir="auto"
            [attr.lang]="data.language"
            data-testid="text-dialog-original"
          >
            {{ data.original }}
          </p>
        </div>
        @if (data.installation !== null) {
          <div class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ 'texts.dialog.installation' | translate }}</span>
            <p
              class="twes-value whitespace-pre-wrap"
              dir="auto"
              [attr.lang]="data.language"
              data-testid="text-dialog-installation"
            >
              {{ data.installation }}
            </p>
          </div>
        }
        <mat-form-field>
          <mat-label>{{ 'texts.dialog.value' | translate }}</mat-label>
          <textarea
            matInput
            rows="4"
            required
            dir="auto"
            [attr.lang]="data.language"
            [formControl]="value"
            data-testid="text-dialog-value"
          ></textarea>
          @if (data.marks.length > 0) {
            <mat-hint data-testid="text-dialog-marks">{{
              'texts.dialog.marks' | translate: { marks: data.marks.join(' ') }
            }}</mat-hint>
          }
          <mat-error data-testid="text-dialog-refusal">{{
            'texts.refusals.' + refusalShown() | translate
          }}</mat-error>
        </mat-form-field>
      </div>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(null)" data-testid="text-dialog-close">
        {{ 'texts.dialog.close' | translate }}
      </button>
      <button
        mat-flat-button
        type="button"
        [disabled]="saving()"
        (click)="save()"
        data-testid="text-dialog-save"
      >
        {{ 'texts.dialog.save' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class TextDialog {
  protected readonly ref = inject<MatDialogRef<TextDialog, SavedText | null>>(MatDialogRef);
  protected readonly data = inject<TextDialogData>(MAT_DIALOG_DATA);
  protected readonly value = new FormControl(this.data.value, {
    nonNullable: true,
    validators: [Validators.required, notBlank],
  });
  protected readonly saving = signal(false);
  /** What the API refused the text for; typing again clears it with the control's errors. */
  private readonly refusal = signal<TextRefusalCode | null>(null);

  /** The refusal under the field: the API's, else a text left empty. */
  protected refusalShown(): TextRefusalCode {
    return this.value.hasError('refused') ? (this.refusal() ?? 'empty') : 'empty';
  }

  protected async save(): Promise<void> {
    if (this.saving()) return;
    if (this.value.invalid) {
      this.value.markAsTouched();
      return;
    }
    this.saving.set(true);
    const outcome = await this.data.save(this.value.value);
    this.saving.set(false);
    if (outcome.kept) {
      this.ref.close(outcome.answer);
      return;
    }
    if (outcome.refusal === null) return;
    this.refusal.set(outcome.refusal);
    this.value.setErrors({ refused: true });
    this.value.markAsTouched();
  }
}

/** A text of spaces alone is empty, as the API reads it. */
function notBlank(control: AbstractControl<string>): { required: true } | null {
  return control.value.trim() === '' ? { required: true } : null;
}
