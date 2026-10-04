// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, forwardRef, input, signal } from '@angular/core';
import {
  type AbstractControl,
  type ControlValueAccessor,
  NG_VALIDATORS,
  NG_VALUE_ACCESSOR,
  type ValidationErrors,
  type Validator,
} from '@angular/forms';
import { TranslatePipe } from '@ngx-translate/core';
import { Label } from '../a11y/label';
import { ACCENT_PRESETS } from '../theme/accent-presets';

const HEX = /^#[0-9a-f]{6}$/;

/**
 * A colour chosen from swatches or written as `#rrggbb`. Swatches are the accents the app offers; the written value is
 * for a brand colour, and cannot break contrast because the theme derives the readable roles from whatever it is given.
 * Text that is not a colour stays in the field with a `colour` error.
 */
@Component({
  selector: 'app-colour-field',
  imports: [Label, TranslatePipe],
  providers: [
    { provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => ColourField), multi: true },
    { provide: NG_VALIDATORS, useExisting: forwardRef(() => ColourField), multi: true },
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'flex min-w-0 flex-col gap-2' },
  template: `
    <div
      role="radiogroup"
      class="flex flex-wrap items-center gap-2"
      [attr.aria-labelledby]="labelledBy() || null"
    >
      @for (preset of presets; track preset.id) {
        <button
          type="button"
          role="radio"
          class="size-8 shrink-0 rounded-full border border-outline outline-offset-2 focus-visible:outline-2 focus-visible:outline-primary"
          [class.ring-2]="isChosen(preset.hex)"
          [class.ring-offset-2]="isChosen(preset.hex)"
          [class.ring-on-surface]="isChosen(preset.hex)"
          [style.background-color]="preset.hex"
          [attr.aria-checked]="isChosen(preset.hex)"
          [attr.tabindex]="stop(preset.hex) ? 0 : -1"
          [appLabel]="'form.colour.' + preset.id | translate"
          [disabled]="disabled()"
          (click)="choose(preset.hex)"
          (keydown)="arrow($event)"
          [attr.data-swatch]="preset.id"
          [attr.data-testid]="testId() + '-swatch-' + preset.id"
        >
          <span class="sr-only">{{ 'form.colour.' + preset.id | translate }}</span>
        </button>
      }
    </div>
    <div class="flex items-center gap-2">
      <span
        aria-hidden="true"
        class="size-8 shrink-0 rounded-control border border-outline"
        [style.background-color]="valid() ? text() : null"
      ></span>
      <input
        type="text"
        class="min-h-10 w-36 rounded-control border border-outline bg-surface px-4 py-1.5 font-mono text-base"
        spellcheck="false"
        autocomplete="off"
        maxlength="7"
        placeholder="#rrggbb"
        [id]="inputId()"
        [value]="text()"
        [disabled]="disabled()"
        [attr.aria-label]="'form.colour.custom' | translate"
        (input)="typed($event)"
        (blur)="touched()"
        [attr.data-testid]="testId()"
      />
    </div>
  `,
})
export class ColourField implements ControlValueAccessor, Validator {
  readonly inputId = input('');
  readonly labelledBy = input('');
  readonly testId = input('');
  protected readonly presets = ACCENT_PRESETS;
  protected readonly text = signal('');
  protected readonly disabled = signal(false);
  private changed: (value: string) => void = () => undefined;
  private onTouched: () => void = () => undefined;

  protected valid(): boolean {
    return HEX.test(this.text());
  }

  /** The one swatch Tab reaches: the chosen one, else the first; arrow keys move among the rest. */
  protected stop(hex: string): boolean {
    const chosen = this.presets.some((preset) => preset.hex === this.text());
    return chosen ? this.isChosen(hex) : hex === this.presets[0].hex;
  }

  protected arrow(event: KeyboardEvent): void {
    const step =
      event.key === 'ArrowRight' || event.key === 'ArrowDown'
        ? 1
        : event.key === 'ArrowLeft' || event.key === 'ArrowUp'
          ? -1
          : 0;
    if (step === 0 || this.disabled()) return;
    event.preventDefault();
    const from = this.presets.findIndex((preset) => preset.hex === this.text());
    const next =
      this.presets[(Math.max(from, 0) + step + this.presets.length) % this.presets.length]!;
    this.choose(next.hex);
    (event.currentTarget as HTMLElement).parentElement
      ?.querySelector<HTMLElement>(`[data-swatch="${next.id}"]`)
      ?.focus();
  }

  protected isChosen(hex: string): boolean {
    return this.text() === hex;
  }

  writeValue(value: unknown): void {
    this.text.set(typeof value === 'string' ? value.toLowerCase() : '');
  }

  registerOnChange(changed: (value: string) => void): void {
    this.changed = changed;
  }

  registerOnTouched(touched: () => void): void {
    this.onTouched = touched;
  }

  setDisabledState(disabled: boolean): void {
    this.disabled.set(disabled);
  }

  validate(control: AbstractControl): ValidationErrors | null {
    const value: unknown = control.value;
    if (typeof value !== 'string' || value === '') return null;
    return HEX.test(value) ? null : { colour: true };
  }

  protected touched(): void {
    this.onTouched();
  }

  protected choose(hex: string): void {
    this.text.set(hex);
    this.changed(hex);
    this.onTouched();
  }

  protected typed(event: Event): void {
    const value = (event.target as HTMLInputElement).value.trim().toLowerCase();
    this.text.set(value);
    this.changed(value);
  }
}
