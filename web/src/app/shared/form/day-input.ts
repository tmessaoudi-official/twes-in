// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  Directive,
  effect,
  ElementRef,
  forwardRef,
  inject,
  Renderer2,
  untracked,
} from '@angular/core';
import {
  type AbstractControl,
  type ControlValueAccessor,
  NG_VALIDATORS,
  NG_VALUE_ACCESSOR,
  type ValidationErrors,
  type Validator,
} from '@angular/forms';
import { parseDay } from '../i18n/format';
import { FormatFacade } from '../i18n/format-facade';

const ISO_DAY = /^\d{4}-\d{2}-\d{2}$/;

/**
 * A day as a person reads and types it: the field shows it in the company's day format ("05/09/2026") and takes it in
 * that order with any separator, while the control keeps the API's ISO day. Text that is not a day stays in the field,
 * and in the control, with a `date` error, so it can be corrected rather than lost.
 */
@Directive({
  selector: 'input[appDay]',
  providers: [
    { provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => DayInput), multi: true },
    { provide: NG_VALIDATORS, useExisting: forwardRef(() => DayInput), multi: true },
  ],
  host: { '(input)': 'typed()', '(blur)': 'left()' },
})
export class DayInput implements ControlValueAccessor, Validator {
  private readonly element = inject<ElementRef<HTMLInputElement>>(ElementRef);
  private readonly renderer = inject(Renderer2);
  private readonly format = inject(FormatFacade);
  private value = '';
  private changed: (value: string) => void = () => undefined;
  private touched: () => void = () => undefined;

  constructor() {
    effect(() => this.show());
  }

  writeValue(value: unknown): void {
    this.value = typeof value === 'string' ? value : '';
    untracked(() => this.show());
  }

  registerOnChange(changed: (value: string) => void): void {
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
    if (typeof value !== 'string' || value === '') return null;
    return ISO_DAY.test(value) ? null : { date: true };
  }

  protected typed(): void {
    const text = this.element.nativeElement.value.trim();
    this.value =
      text === '' ? '' : (parseDay(text, this.format.locale(), this.format.dateFormat()) ?? text);
    this.changed(this.value);
  }

  protected left(): void {
    this.show();
    this.touched();
  }

  /** Reads the locale and the chosen format, so the effect shows the field again when either moves. */
  private show(): void {
    const shown = ISO_DAY.test(this.value) ? this.format.day(this.value) : this.value;
    this.renderer.setProperty(this.element.nativeElement, 'value', shown);
  }
}
