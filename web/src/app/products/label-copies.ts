// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, ElementRef, inject, model } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import {
  type AbstractControl,
  FormControl,
  ReactiveFormsModule,
  type ValidationErrors,
  Validators,
} from '@angular/forms';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe } from '@ngx-translate/core';
import { revealInvalid } from '../shared/form/reveal-invalid';
import { MOST_LABEL_COPIES } from './product-label';

/**
 * How many copies of each label a sheet prints, the same field on a product's sheet and on a sheet of several: a whole
 * number from one to the ceiling. A number outside it was put back within it without a word (« 150 » printed 100);
 * now the field keeps what was typed and says the limit under it, the sheet keeps the last count it could print, and
 * printing first says what to change (`reveal`).
 */
@Component({
  selector: 'app-label-copies',
  imports: [ReactiveFormsModule, MatFormFieldModule, MatInputModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <mat-form-field class="w-32">
      <mat-label>{{ 'products.labels.copies' | translate }}</mat-label>
      <input
        matInput
        type="number"
        min="1"
        [max]="most"
        step="1"
        data-testid="product-labels-copies"
        [formControl]="field"
      />
      @if (field.hasError('required')) {
        <mat-error data-testid="product-labels-copies-error">{{
          'form.errors.required' | translate
        }}</mat-error>
      } @else if (field.hasError('min')) {
        <mat-error data-testid="product-labels-copies-error">{{
          'form.errors.min' | translate: { min: 1 }
        }}</mat-error>
      } @else if (field.hasError('max')) {
        <mat-error data-testid="product-labels-copies-error">{{
          'form.errors.max' | translate: { max: most }
        }}</mat-error>
      } @else if (field.hasError('whole')) {
        <mat-error data-testid="product-labels-copies-error">{{
          'form.errors.pattern' | translate
        }}</mat-error>
      }
    </mat-form-field>
  `,
})
export class LabelCopies {
  /** The copies the sheet prints: the field's value whenever it is one the sheet can print. */
  readonly count = model(1);
  protected readonly most = MOST_LABEL_COPIES;
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
  protected readonly field = new FormControl<number | null>(1, [
    Validators.required,
    Validators.min(1),
    Validators.max(MOST_LABEL_COPIES),
    whole,
  ]);

  constructor() {
    this.field.valueChanges.pipe(takeUntilDestroyed()).subscribe((value) => {
      if (this.field.valid && value !== null) this.count.set(value);
    });
  }

  /** Says what is wrong under the field and puts the cursor there; true when the sheet should not print yet. */
  reveal(): boolean {
    return revealInvalid(this.field, this.host);
  }
}

function whole(control: AbstractControl): ValidationErrors | null {
  const value: unknown = control.value;
  return value === null || Number.isInteger(value) ? null : { whole: true };
}
