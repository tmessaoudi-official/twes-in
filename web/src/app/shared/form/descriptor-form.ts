// SPDX-License-Identifier: AGPL-3.0-or-later

import {
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
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslatePipe } from '@ngx-translate/core';
import { FormatFacade } from '../i18n/format-facade';
import { DecimalInput } from './decimal-input';
import { type DescriptorFormGroup, type FieldError, fieldError } from './form-builder';
import type { FieldConflict } from './form-merge';
import type { FieldValue, FormDescriptor, FormField, FormSection, FormValues } from './form-types';
import { applicableValues, applyVisibility, fieldApplies } from './form-visibility';

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
    MatSelectModule,
    TranslatePipe,
    DecimalInput,
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
  readonly submitted = output<FormValues>();
  /** Fields another person's saved version just changed, highlighted for a moment (docs/SPEC.md § 7, 2026-09-17). */
  readonly updated = input<ReadonlySet<string>>(new Set());
  /** Fields both people changed: each shows the other version and a choice. */
  readonly conflicts = input<readonly FieldConflict[]>([]);
  readonly resolved = output<{ field: string; choice: 'theirs' | 'mine' }>();

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

  protected applies(field: FormField): boolean {
    this.revision();
    return fieldApplies(field, this.form().getRawValue());
  }

  protected errorFor(field: FormField): FieldError | null {
    this.revision();
    const control = this.form().controls[field.id];
    return control?.touched ? fieldError(control, field) : null;
  }

  protected conflictFor(field: FormField): FieldConflict | null {
    return this.conflicts().find((conflict) => conflict.field === field.id) ?? null;
  }

  /** A value as the field shows it: a choice by its label, a box as ticked or not, a decimal as the locale writes it. */
  protected shown(field: FormField, value: FieldValue): string {
    if (value === null || value === '') return '—';
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
    return field.span === 2 ? 'sm:col-span-2' : '';
  }

  protected submit(): void {
    const form = this.form();
    if (this.readOnly()) return;
    applyVisibility(this.descriptor(), form);
    if (form.invalid) {
      form.markAllAsTouched();
      this.revision.update((revision) => revision + 1);
      this.focusFirstInvalid();
      return;
    }
    this.submitted.emit(applicableValues(this.descriptor(), form));
  }

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
