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
import { type ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';
import { decimalShown, decimalTyped } from '../i18n/format';
import { FormatFacade } from '../i18n/format-facade';

/**
 * A decimal field as a person reads and types it (docs/SPEC.md § 7, 2026-09-19 21:55): the control keeps the API's
 * own string ("890.000"), the field shows it with the screen's decimal separator ("890,000" in French) and takes a
 * comma or a point. Validators, saving and the merge of another person's save only ever see the API's form.
 */
@Directive({
  selector: 'input[appDecimal]',
  providers: [
    { provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => DecimalInput), multi: true },
  ],
  host: { inputmode: 'decimal', '(input)': 'typed()', '(blur)': 'left()' },
})
export class DecimalInput implements ControlValueAccessor {
  private readonly element = inject<ElementRef<HTMLInputElement>>(ElementRef);
  private readonly renderer = inject(Renderer2);
  private readonly locale = inject(FormatFacade).locale;
  private value = '';
  private changed: (value: string) => void = () => undefined;
  private touched: () => void = () => undefined;

  constructor() {
    effect(() => this.show(this.locale()));
  }

  writeValue(value: unknown): void {
    this.value = typeof value === 'string' ? value : value == null ? '' : String(value);
    this.show(untracked(this.locale));
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

  protected typed(): void {
    this.value = decimalTyped(this.element.nativeElement.value);
    this.changed(this.value);
  }

  protected left(): void {
    this.show(this.locale());
    this.touched();
  }

  private show(locale: string): void {
    this.renderer.setProperty(
      this.element.nativeElement,
      'value',
      decimalShown(this.value, locale),
    );
  }
}
