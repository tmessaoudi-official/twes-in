// SPDX-License-Identifier: AGPL-3.0-or-later

import { Directive, ElementRef, forwardRef, inject, Renderer2 } from '@angular/core';
import {
  type AbstractControl,
  type ControlValueAccessor,
  NG_VALIDATORS,
  NG_VALUE_ACCESSOR,
  type ValidationErrors,
  type Validator,
} from '@angular/forms';
import { decimalTyped } from '../i18n/format';

/**
 * A count as typed in a text field: empty is NaN, said as emptied; a number written with a comma or a point is that
 * number, « 1,5 » one and a half, which is not whole; anything else is no number at all, Infinity, never whole either.
 * The invisible marks a keyboard or a paste leaves at its ends are not part of it.
 */
export function typedCount(raw: string): number {
  const typed = decimalTyped(raw);
  if (typed === '') return Number.NaN;
  return /^-?\d+(\.\d+)?$/.test(typed) ? Number(typed) : Number.POSITIVE_INFINITY;
}

/**
 * A whole number typed in a text field with the numeric keyboard (row 242), never `type="number"`, which hands the form
 * an empty value for a French « 1,5 » and for whatever else it cannot read, so the typing is lost unsaid. The control
 * holds the number typed, null when emptied; one that is not whole, or no number at all, is refused as `wholeNumber`,
 * which a form says as « Attendu : un nombre entier. ». `Validators.min` and `max` keep working on what it holds.
 */
@Directive({
  selector: 'input[appCount]',
  providers: [
    { provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => CountInput), multi: true },
    { provide: NG_VALIDATORS, useExisting: forwardRef(() => CountInput), multi: true },
  ],
  host: { inputmode: 'numeric', autocomplete: 'off', '(input)': 'typed()', '(blur)': 'left()' },
})
export class CountInput implements ControlValueAccessor, Validator {
  private readonly element = inject<ElementRef<HTMLInputElement>>(ElementRef);
  private readonly renderer = inject(Renderer2);
  private changed: (value: number | null) => void = () => undefined;
  private touched: () => void = () => undefined;

  writeValue(value: unknown): void {
    // A number not held (NaN, Infinity) is what the person typed and is still on screen: it stays as typed.
    if (typeof value === 'number' && !Number.isFinite(value)) return;
    this.renderer.setProperty(
      this.element.nativeElement,
      'value',
      typeof value === 'number' ? String(value) : '',
    );
  }

  registerOnChange(changed: (value: number | null) => void): void {
    this.changed = changed;
  }

  registerOnTouched(touched: () => void): void {
    this.touched = touched;
  }

  setDisabledState(disabled: boolean): void {
    this.renderer.setProperty(this.element.nativeElement, 'disabled', disabled);
  }

  validate(control: AbstractControl): ValidationErrors | null {
    const value: unknown = control.value;
    return typeof value === 'number' && !Number.isInteger(value) ? { wholeNumber: true } : null;
  }

  protected typed(): void {
    const count = typedCount(this.element.nativeElement.value);
    this.changed(Number.isNaN(count) ? null : count);
  }

  protected left(): void {
    this.touched();
  }
}
