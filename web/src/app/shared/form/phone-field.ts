// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  forwardRef,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import {
  type ControlValueAccessor,
  FormControl,
  NG_VALUE_ACCESSOR,
  ReactiveFormsModule,
} from '@angular/forms';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { phoneCountries, phoneExample, phoneValue, readPhone, samePhone } from './phone-numbers';
import { RefusedField } from './refused-field';
import { Select, type SelectOption } from './select';

let nextId = 0;

/**
 * The one phone field (row 252): the country picked beside the local number, searchable by the country's name in the
 * screen's language or its calling code, and the form handed the number in international form (« +216 71 234 567 »).
 * A kept number is read back into its country and local part when it parses, and shown as it was saved when it does not
 * (a number kept from before the rule), the form's value left as it was until the person changes it. The same number
 * typed again hands back the value the form opened on, so it counts as no change.
 */
@Component({
  selector: 'app-phone-field',
  imports: [
    MatFormFieldModule,
    MatInputModule,
    ReactiveFormsModule,
    RefusedField,
    Select,
    TranslatePipe,
  ],
  providers: [
    { provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => PhoneField), multi: true },
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  // Two boxes side by side, which wrap one under the other where the field is too narrow for both: on a phone.
  host: { class: 'flex min-w-0 flex-wrap items-start gap-2' },
  template: `
    <span [id]="countryLabelId" class="sr-only">{{ 'form.phone.country' | translate }}</span>
    <app-select
      class="min-w-0 flex-[1_1_13rem]"
      [labelledBy]="countryLabelId"
      [options]="countryOptions()"
      [formControl]="countryControl"
      [testId]="testId() + '-country'"
    />
    <mat-form-field class="min-w-0 flex-[1_1_10rem]">
      <input
        matInput
        type="tel"
        inputmode="tel"
        autocomplete="tel-national"
        [id]="inputId()"
        [value]="local()"
        [disabled]="disabled()"
        [appRefused]="error() !== ''"
        [attr.aria-required]="required() || null"
        [attr.data-testid]="testId()"
        (input)="typed($event)"
        (blur)="onTouched()"
      />
      @if (error() !== '') {
        <!-- value-direction: a sentence in the screen's language, read in the screen's direction -->
        <mat-error [attr.data-testid]="errorTestId() || null">{{ error() }}</mat-error>
      } @else if (shownHint() !== '') {
        <!-- value-direction: a sentence in the screen's language, read in the screen's direction -->
        <mat-hint [attr.data-testid]="testId() ? testId() + '-hint' : null">{{
          shownHint()
        }}</mat-hint>
      }
    </mat-form-field>
  `,
})
export class PhoneField implements ControlValueAccessor {
  /** The country a number with no « + » is read by: the company's, else the platform's. */
  readonly country = input('');
  readonly inputId = input('');
  readonly testId = input('');
  readonly required = input(false);
  /** What is said under the number, already in the screen's words: the refusal, else the hint. */
  readonly error = input('');
  readonly errorTestId = input('');
  readonly hint = input('');

  protected readonly countryLabelId = `app-phone-field-${nextId++}-country`;
  protected readonly countryControl = new FormControl<string>('', { nonNullable: true });
  /** The country picked, as a signal: the control is set without events when a value is read in. */
  private readonly picked = signal('');
  protected readonly local = signal('');
  protected readonly disabled = signal(false);
  /** What the form opened on: handed back when the same number is typed again. */
  private opened = '';
  /** Whether a value was read in, after which the picker follows it rather than the default country. */
  private read = false;
  private changed: (value: string) => void = () => undefined;
  protected onTouched: () => void = () => undefined;

  private readonly translate = inject(TranslateService);
  private readonly language = toSignal(this.translate.onLangChange, { initialValue: null });

  /**
   * What is said under the number while nothing is refused: the form's own hint, else what the box asks for, the
   * number without its code, with an example of the country picked, isolated left to right so its groups keep their
   * order on an Arabic screen.
   */
  protected readonly shownHint = computed(() => {
    if (this.hint() !== '') return this.hint();
    this.language();
    const example = phoneExample(this.picked());
    return example === ''
      ? ''
      : (this.translate.instant('form.phone.hint', {
          example: `\u2066${example}\u2069`,
        }) as string);
  });

  protected readonly countryOptions = computed<readonly SelectOption[]>(() => {
    this.language();
    const screen = this.translate.getCurrentLang() ?? this.translate.getFallbackLang() ?? '';
    return phoneCountries(screen).map((country) => ({
      value: country.code,
      // « 🇹🇳 Tunisie +216 », as ruled: the name found by the search, the code isolated left to right so « +216 » keeps
      // its sign before its digits in Arabic.
      label: `${country.flag} ${country.name} \u2066+${country.callingCode}\u2069`,
      testId: `${this.testId()}-country-${country.code}`,
    }));
  });

  constructor() {
    // An empty field starts on the default country, which may arrive after the form: the session is read late.
    effect(() => {
      const country = this.country();
      untracked(() => {
        if (!this.read || this.countryControl.value === '' || this.local() === '') {
          this.countryControl.setValue(country, { emitEvent: false });
          this.picked.set(country);
        }
      });
    });
    this.countryControl.valueChanges
      .pipe(takeUntilDestroyed(inject(DestroyRef)))
      .subscribe((country) => {
        this.picked.set(country);
        if (this.local() !== '') this.emit();
      });
  }

  protected typed(event: Event): void {
    this.local.set((event.target as HTMLInputElement).value);
    this.emit();
  }

  private emit(): void {
    const value = phoneValue(this.countryControl.value, this.local());
    this.changed(this.opened !== '' && samePhone(value, this.opened) ? this.opened : value);
  }

  writeValue(value: unknown): void {
    this.opened = typeof value === 'string' ? value : '';
    const read = readPhone(
      this.opened,
      untracked(() => this.country()),
    );
    this.read = this.opened !== '';
    this.countryControl.setValue(read.country, { emitEvent: false });
    this.picked.set(read.country);
    this.local.set(read.local);
  }

  registerOnChange(fn: (value: string) => void): void {
    this.changed = fn;
  }

  registerOnTouched(fn: () => void): void {
    this.onTouched = fn;
  }

  setDisabledState(disabled: boolean): void {
    this.disabled.set(disabled);
    if (disabled) {
      this.countryControl.disable({ emitEvent: false });
    } else {
      this.countryControl.enable({ emitEvent: false });
    }
  }
}
