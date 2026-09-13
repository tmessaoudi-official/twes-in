// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  type AbstractControl,
  FormControl,
  FormGroup,
  type ValidationErrors,
  type ValidatorFn,
  Validators,
} from '@angular/forms';
import type { FieldValue, FormDescriptor, FormField, FormValues } from './form-types';

/**
 * A form as a pure function of its descriptor: one control per declared field with the validators the field
 * declares, and one translated message per invalid field. DescriptorForm only renders what this builds.
 */

export class InvalidFormField extends Error {
  constructor(id: string, reason: string) {
    super(`form field "${id}": ${reason}`);
    this.name = 'InvalidFormField';
  }
}

export class DuplicateFormField extends Error {
  constructor(id: string) {
    super(`form field "${id}" is already declared`);
    this.name = 'DuplicateFormField';
  }
}

export class UnknownFormSection extends Error {
  constructor(id: string) {
    super(`form section "${id}" does not exist`);
    this.name = 'UnknownFormSection';
  }
}

export interface FieldError {
  /** A translation key under `form.errors`. */
  key: string;
  params?: Record<string, number>;
}

export type DescriptorFormGroup = FormGroup<Record<string, FormControl<FieldValue>>>;

const isBlank = (value: unknown): boolean =>
  value === null || value === undefined || (typeof value === 'string' && value.trim() === '');

/** Unlike Validators.required, a value made only of spaces is missing too. */
const requiredValue: ValidatorFn = (control) =>
  isBlank(control.value) ? { required: true } : null;

function oneOf(field: FormField): ValidatorFn {
  const allowed = new Set((field.options ?? []).map((option) => option.value));
  return (control) =>
    isBlank(control.value) || allowed.has(String(control.value)) ? null : { option: true };
}

function validatorsFor(field: FormField): ValidatorFn[] {
  const validators: ValidatorFn[] = [];
  if (field.required) validators.push(requiredValue);
  if (field.kind === 'email') validators.push(Validators.email);
  if (field.kind === 'select') validators.push(oneOf(field));
  // A string pattern is anchored at both ends by Validators.pattern, so it matches the whole value.
  if (field.pattern !== undefined) validators.push(Validators.pattern(field.pattern));
  if (field.minLength !== undefined) validators.push(Validators.minLength(field.minLength));
  if (field.maxLength !== undefined) validators.push(Validators.maxLength(field.maxLength));
  if (field.min !== undefined) validators.push(Validators.min(field.min));
  if (field.max !== undefined) validators.push(Validators.max(field.max));
  return validators;
}

function assertValidField(field: FormField): void {
  if (field.kind === 'select' && (field.options ?? []).length === 0) {
    throw new InvalidFormField(field.id, 'a select needs at least one option');
  }
}

const emptyValue = (field: FormField): FieldValue => (field.kind === 'number' ? null : '');

/** Controls for every declared field, starting from `initial`, then the declared default, then empty. */
export function buildFormGroup(
  descriptor: FormDescriptor,
  initial: FormValues = {},
): DescriptorFormGroup {
  const controls: Record<string, FormControl<FieldValue>> = {};
  for (const section of descriptor.sections) {
    for (const field of section.fields) {
      assertValidField(field);
      const start = Object.hasOwn(initial, field.id)
        ? initial[field.id]
        : (field.defaultValue ?? emptyValue(field));
      controls[field.id] = new FormControl<FieldValue>(start, {
        validators: validatorsFor(field),
      });
    }
  }
  return new FormGroup(controls);
}

/** The one message a person sees under a field, most fundamental problem first; null when the value is valid. */
export function fieldError(control: AbstractControl, field: FormField): FieldError | null {
  const errors: ValidationErrors | null = control.errors;
  if (!errors) return null;
  if (errors['required']) return { key: 'form.errors.required' };
  if (errors['email']) return { key: 'form.errors.email' };
  if (errors['option']) return { key: 'form.errors.option' };
  if (errors['pattern']) return { key: 'form.errors.pattern' };
  if (errors['minlength'])
    return { key: 'form.errors.min_length', params: { min: field.minLength! } };
  if (errors['maxlength'])
    return { key: 'form.errors.max_length', params: { max: field.maxLength! } };
  if (errors['min']) return { key: 'form.errors.min', params: { min: field.min! } };
  if (errors['max']) return { key: 'form.errors.max', params: { max: field.max! } };
  return null;
}

/** The one place configured fields join a form's declared ones. */
export function withCustomFields(
  descriptor: FormDescriptor,
  sectionId: string,
  fields: readonly FormField[],
): FormDescriptor {
  if (!descriptor.sections.some((section) => section.id === sectionId)) {
    throw new UnknownFormSection(sectionId);
  }
  const known = new Set(
    descriptor.sections.flatMap((section) => section.fields.map((field) => field.id)),
  );
  for (const field of fields) {
    if (known.has(field.id)) throw new DuplicateFormField(field.id);
    known.add(field.id);
  }
  return {
    ...descriptor,
    sections: descriptor.sections.map((section) =>
      section.id === sectionId ? { ...section, fields: [...section.fields, ...fields] } : section,
    ),
  };
}
