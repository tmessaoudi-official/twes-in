// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  effect,
  ElementRef,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { ReactiveFormsModule } from '@angular/forms';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslatePipe } from '@ngx-translate/core';
import { type DescriptorFormGroup, type FieldError, fieldError } from './form-builder';
import type { FormDescriptor, FormField, FormValues } from './form-types';
import { applicableValues, applyVisibility, fieldApplies } from './form-visibility';

/**
 * Every form: the descriptor's sections as titled groups on a two-column grid (one column on a phone), each field
 * with its label, an "optional" hint, and its one message once it has been left. A field with a condition appears
 * only while it applies, and is neither validated nor submitted otherwise. The screen builds the group with
 * buildFormGroup and projects its own buttons; a valid submit emits the values of the fields that apply, an invalid
 * one shows every error and focuses the first invalid field.
 */
@Component({
  selector: 'app-descriptor-form',
  imports: [
    ReactiveFormsModule,
    MatCheckboxModule,
    MatFormFieldModule,
    MatInputModule,
    MatSelectModule,
    TranslatePipe,
  ],
  templateUrl: './descriptor-form.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DescriptorForm {
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);

  readonly descriptor = input.required<FormDescriptor>();
  readonly form = input.required<DescriptorFormGroup>();
  readonly testId = input('descriptor-form');
  readonly submitted = output<FormValues>();

  /** Bumped on every value, status or touched change, so an OnPush template re-reads the messages. */
  private readonly revision = signal(0);

  constructor() {
    effect((onCleanup) => {
      const descriptor = this.descriptor();
      const form = this.form();
      applyVisibility(descriptor, form);
      const subscription = form.events.subscribe(() => {
        applyVisibility(descriptor, form);
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

  protected spanClass(field: FormField): string {
    return field.span === 2 ? 'sm:col-span-2' : '';
  }

  protected submit(): void {
    const form = this.form();
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
