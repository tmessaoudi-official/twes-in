// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
  effect,
  ElementRef,
  inject,
  Injector,
  input,
  output,
  signal,
} from '@angular/core';
import { ReactiveFormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatAutocompleteModule } from '@angular/material/autocomplete';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { type Subscription, take } from 'rxjs';
import { FormatFacade } from '../i18n/format-facade';
import { Label } from '../a11y/label';
import { DecimalInput } from './decimal-input';
import { ColourField } from './colour-field';
import { DayCalendarButton } from './day-calendar-button';
import { DayInput } from './day-input';
import { PickField, type PickOption } from './pick-field';
import { Select } from './select';
import { type DescriptorFormGroup, type FieldError, fieldError } from './form-builder';
import type { FieldRefusal } from './field-refusals';
import type { FieldConflict } from './form-merge';
import type { FieldValue, FormDescriptor, FormField, FormSection, FormValues } from './form-types';
import { applicableValues, applyVisibility, fieldApplies } from './form-visibility';

/** Where one `pick` field's rows come from, and the record it holds now as that record itself says it. */
export interface PickSource {
  search: (words: string) => Promise<readonly PickOption[]>;
  value: PickOption | null;
}

/**
 * Every form: the descriptor's sections, each titled and explained beside a two-column grid of fields (stacked, one
 * column, on a phone), each field with its label above it marked "optional" where it is, and its one message once it has been left. A field with a condition appears
 * only while it applies, and is neither validated nor submitted otherwise. The screen builds the group with
 * buildFormGroup and projects its own buttons; a valid submit emits the values of the fields that apply, an invalid
 * one shows every error and focuses the first invalid field.
 */
@Component({
  selector: 'app-descriptor-form',
  imports: [
    ReactiveFormsModule,
    MatButtonModule,
    MatCheckboxModule,
    MatFormFieldModule,
    MatInputModule,
    MatAutocompleteModule,
    MatIconModule,
    Label,
    TranslatePipe,
    DecimalInput,
    DayInput,
    DayCalendarButton,
    ColourField,
    PickField,
    Select,
  ],
  templateUrl: './descriptor-form.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DescriptorForm {
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly injector = inject(Injector);

  readonly descriptor = input.required<FormDescriptor>();
  readonly form = input.required<DescriptorFormGroup>();
  readonly testId = input('descriptor-form');
  /** Shows the values with every field disabled, for someone who may read but not change them. */
  readonly readOnly = input(false);
  /**
   * The section whose fields begin with what the screen projects as `formLead`: a field the screen keeps itself, such
   * as a document's customer picker, then sits under its own section's heading rather than above the form.
   */
  readonly leadIn = input<string | null>(null);
  readonly submitted = output<FormValues>();
  /** Fields another person's saved version just changed, highlighted for a moment (docs/SPEC.md § 7, 2026-09-17). */
  readonly updated = input<ReadonlySet<string>>(new Set());
  /** Fields both people changed: each shows the other version and a choice. */
  readonly conflicts = input<readonly FieldConflict[]>([]);
  readonly resolved = output<{ field: string; choice: 'theirs' | 'mine' }>();
  /**
   * Where a `pick` field's rows come from, per field id, and which record it holds now. It is an input rather than
   * part of the descriptor because a descriptor is DATA — it is stringified to key the form — and a search is a
   * function. The screen owns the asking; this only shows what comes back and writes the chosen id into the control.
   */
  readonly pickers = input<Record<string, PickSource>>({});
  /**
   * Values already in use that a text field offers as it is typed in, per field id; anything else may still be typed.
   * An input, like `pickers`, and not part of the descriptor, so suggestions that arrive later never rebuild the form.
   */
  readonly suggestions = input<Readonly<Record<string, readonly string[]>>>({});
  /**
   * Fields the screen keeps off it for now, such as a name in a language the company does not use: they stay in the
   * form with what they hold, and one holding a value is shown whatever this says, so nothing typed or saved is ever
   * out of sight. Inputs rather than descriptor data, like `pickers`, so choosing them never rebuilds the form.
   */
  readonly withheld = input<ReadonlySet<string>>(new Set());
  /** Fields offering a button that empties them and tells the screen through `removed`, which then withholds them. */
  readonly removable = input<ReadonlySet<string>>(new Set());
  readonly removed = output<string>();
  /** A word written after a field's label, per field id, as a translation key: « langue des documents ». */
  readonly notes = input<Readonly<Record<string, string>>>({});
  /** The section whose fields end with what the screen projects as `formTrail`, such as « Ajouter une langue ». */
  readonly trailIn = input<string | null>(null);

  /** Bumped on every value, status or touched change, so an OnPush template re-reads the messages. */
  private readonly revision = signal(0);

  constructor() {
    effect((onCleanup) => {
      const descriptor = this.descriptor();
      const form = this.form();
      const readOnly = this.readOnly();
      applyVisibility(descriptor, form, readOnly);
      const subscription = form.events.subscribe(() => {
        applyVisibility(descriptor, form, readOnly);
        this.revision.update((revision) => revision + 1);
      });
      onCleanup(() => subscription.unsubscribe());
    });
  }

  protected pickerFor(field: FormField): PickSource | null {
    return this.pickers()[field.id] ?? null;
  }

  /** What a picker answered goes into the control as its id, exactly as a select would put an option's value there. */
  protected takePicked(field: FormField, option: PickOption | null): void {
    this.form().controls[field.id]?.setValue(option?.id ?? '');
    this.form().controls[field.id]?.markAsTouched();
  }

  protected applies(field: FormField): boolean {
    this.revision();
    const values = this.form().getRawValue();
    if (this.withheld().has(field.id) && isBlank(values[field.id])) return false;
    return fieldApplies(field, values);
  }

  /** The remove button's name, for a field the screen lets go; none for any other, or on a read-only form. */
  protected removeLabelOf(field: FormField): string | null {
    if (this.readOnly() || !this.removable().has(field.id)) return null;
    return field.removeLabel ?? null;
  }

  /**
   * Empties the field and tells the screen, which withholds it; the person is then put on the section's trail (where
   * the field can be asked for again), since the button they pressed is gone.
   */
  protected remove(field: FormField): void {
    const control = this.form().controls[field.id];
    control?.setValue('');
    control?.markAsDirty();
    this.removed.emit(field.id);
    afterNextRender(
      () => {
        const host = this.host.nativeElement;
        if (host.querySelector(`[data-testid="field-${field.id}"]`)) return;
        host
          .querySelector<HTMLElement>(
            '.twes-form-trail button:not([disabled]), .twes-form-trail [href]',
          )
          ?.focus();
      },
      { injector: this.injector },
    );
  }

  protected errorFor(field: FormField): FieldError | null {
    this.revision();
    const control = this.form().controls[field.id];
    return control?.touched ? fieldError(control, field) : null;
  }

  /** What the field's suggestions hold of what is typed, whatever its case; none once it is typed in full. */
  protected suggested(field: FormField): readonly string[] {
    this.revision();
    const values = this.suggestions()[field.id] ?? [];
    const typed = String(this.form().controls[field.id]?.value ?? '')
      .trim()
      .toLocaleLowerCase();
    return values.filter((value) => {
      const known = value.toLocaleLowerCase();
      return known !== typed && known.includes(typed);
    });
  }

  protected hasSuggestions(field: FormField): boolean {
    return (this.suggestions()[field.id]?.length ?? 0) > 0;
  }

  protected conflictFor(field: FormField): FieldConflict | null {
    return this.conflicts().find((conflict) => conflict.field === field.id) ?? null;
  }

  /** A value as the field shows it: a choice by its label, a box as ticked or not, a decimal as the locale writes it. */
  protected shown(field: FormField, value: FieldValue): string {
    if (value === null || value === '') return '—';
    if (Array.isArray(value)) {
      if (value.length === 0) return '—';
      // Several choices read as their labels, which are keys or a company's own words: translated here, since the
      // template translates what it is given as one key.
      const translate = this.injector.get(TranslateService);
      return (
        field.options
          ?.filter((candidate) => value.includes(candidate.value))
          .map((candidate) => translate.instant(candidate.label))
          .join(', ') ?? value.join(', ')
      );
    }
    if (field.kind === 'checkbox') return value === true ? '✓' : '✗';
    // Asked for only here, so a form with no decimal field never needs the session behind the format.
    if (field.kind === 'decimal')
      return this.injector.get(FormatFacade).amount(String(value), null);
    const option = field.options?.find((candidate) => candidate.value === value);
    return option ? option.label : String(value);
  }

  /** Unique per form on the page, so two forms with the same field ids never share a label. */
  protected controlId(field: FormField): string {
    return `${this.testId()}-${field.id}`;
  }

  protected sectionId(section: FormSection): string {
    return `${this.testId()}-section-${section.id}`;
  }

  protected spanClass(field: FormField): string {
    return field.span === 2 ? '@min-[30rem]:col-span-2' : '';
  }

  protected submit(): void {
    const form = this.form();
    if (this.readOnly()) return;
    applyVisibility(this.descriptor(), form);
    if (form.invalid) {
      this.showErrors();
      return;
    }
    this.submitted.emit(applicableValues(this.descriptor(), form));
  }

  /**
   * What a save refused for the fields in error does, from the form or from a bar outside it: each error shown, and the
   * person put on the first, so its label and error are what they hear next.
   */
  showErrors(): void {
    const form = this.form();
    applyVisibility(this.descriptor(), form);
    form.markAllAsTouched();
    this.revision.update((revision) => revision + 1);
    this.focusFirstInvalid();
  }

  /**
   * What the API refused, each under its own field in the text a file is refused with (« Valeur refusée. » for a
   * reason the screens do not know), and the person put on the first. A refusal holds the save until its field
   * changes. Hands back what no field shown here can carry, for the screen to say another way.
   */
  showRefused(refusals: readonly FieldRefusal[]): readonly FieldRefusal[] {
    const form = this.form();
    applyVisibility(this.descriptor(), form, this.readOnly());
    const translate = this.injector.get(TranslateService);
    const unplaced: FieldRefusal[] = [];
    for (const refusal of refusals) {
      const control = form.controls[refusal.field];
      if (!control || control.disabled) {
        unplaced.push(refusal);
        continue;
      }
      const key = `import.rejections.${refusal.reason ?? ''}`;
      const known = refusal.reason !== null && translate.instant(key) !== key;
      const error: FieldError = {
        key: known ? key : 'import.rejections.invalid_value',
        params: refusal.params,
      };
      control.setErrors({ refused: error });
      control.markAsTouched();
    }
    // A refusal is often mended in another field (the regime, for taxes it does not charge): the first change
    // anywhere lets the next save ask the API again rather than holding it on what it last said.
    this.refusalsCleared?.unsubscribe();
    this.refusalsCleared = form.valueChanges.pipe(take(1)).subscribe(() => {
      for (const control of Object.values(form.controls)) {
        if (control.hasError('refused')) control.updateValueAndValidity();
      }
      this.revision.update((revision) => revision + 1);
    });
    this.revision.update((revision) => revision + 1);
    this.focusFirstInvalid();
    return unplaced;
  }

  /** Drops what the API last refused at the form's next change. */
  private refusalsCleared: Subscription | null = null;

  private focusFirstInvalid(): void {
    const first = this.descriptor()
      .sections.flatMap((section) => section.fields)
      .find((field) => this.form().controls[field.id]?.invalid);
    if (!first) return;
    this.host.nativeElement
      .querySelector<HTMLElement>(`[data-testid="field-${first.id}"]`)
      ?.focus();
  }
}

function isBlank(value: FieldValue | undefined): boolean {
  if (value === null || value === undefined) return true;
  if (Array.isArray(value)) return value.length === 0;
  return typeof value === 'string' && value.trim() === '';
}
