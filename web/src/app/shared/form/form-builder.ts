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
import { onTypedText, typedText } from './typed-text';
import { emailRule } from './email-rule';
import { lengthSaidOf } from './length-said';

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
  /** A translation key: under `form.errors` for the form's own rules, `import.rejections` for what the API refused. */
  key: string;
  params?: Readonly<Record<string, number | string>>;
  /**
   * The field's hint, a translation key, when the error alone does not state the rule: Material hides the hint while
   * the error shows, and « not the expected format » says nothing about which one (MSG-03, WCAG 3.3.3).
   */
  hint?: string;
  /** How many characters are there out of how many fit (« 5003 / 5000 »), said beside a value too long. */
  count?: string;
}

export type DescriptorFormGroup = FormGroup<Record<string, FormControl<FieldValue>>>;

const isBlank = (value: unknown): boolean =>
  value === null ||
  value === undefined ||
  (typeof value === 'string' && typedText(value) === '') ||
  (Array.isArray(value) && value.length === 0);

/** The fields a person types a text into, whose ends a paste fills with spaces and invisible marks nobody meant. */
const TYPED_KINDS: ReadonlySet<FormField['kind']> = new Set([
  'text',
  'email',
  'tel',
  'url',
  'textarea',
]);

const typesText = (field: FormField): boolean =>
  TYPED_KINDS.has(field.kind) && field.readOnly !== true;

/**
 * A typed field's control keeps what the person typed, so a space before the next word is not eaten as it is typed,
 * and hands on what was typed without its ends: every screen reads a form through `getRawValue()`, from its own submit
 * or from the bar beside its title, so this is the one place a save is given the value the API keeps.
 */
function typedTextControl(start: FieldValue, validators: ValidatorFn[]): FormControl<FieldValue> {
  const control = new FormControl<FieldValue>(start, { validators });
  const asTyped = control.getRawValue.bind(control);
  control.getRawValue = () => {
    const value = asTyped();
    return typeof value === 'string' ? typedText(value) : value;
  };
  return control;
}

/** Unlike Validators.required, a value made only of spaces is missing too. */
const requiredValue: ValidatorFn = (control) =>
  isBlank(control.value) ? { required: true } : null;

function oneOf(field: FormField): ValidatorFn {
  const allowed = new Set((field.options ?? []).map((option) => option.value));
  return (control) => {
    const value: unknown = control.value;
    if (isBlank(value)) return null;
    // A multiselect holds a list, and every one of its values must be offered.
    const held = Array.isArray(value) ? value.map(String) : [String(value)];
    return held.every((each) => allowed.has(each)) ? null : { option: true };
  };
}

function validatorsFor(field: FormField): ValidatorFn[] {
  const validators: ValidatorFn[] = [];
  // A required checkbox is one that must be ticked; requiredTrue reports the same `required` error.
  if (field.required)
    validators.push(field.kind === 'checkbox' ? Validators.requiredTrue : requiredValue);
  const typed = typesText(field) ? onTypedText : (validator: ValidatorFn) => validator;
  if (field.kind === 'email') validators.push(typed(emailRule));
  if (field.kind === 'select' || field.kind === 'multiselect') validators.push(oneOf(field));
  // A string pattern is anchored at both ends by Validators.pattern, so it matches the whole value.
  if (field.pattern !== undefined) validators.push(typed(Validators.pattern(field.pattern)));
  if (field.minLength !== undefined) validators.push(typed(Validators.minLength(field.minLength)));
  if (field.maxLength !== undefined) validators.push(typed(Validators.maxLength(field.maxLength)));
  if (field.min !== undefined) validators.push(Validators.min(field.min));
  if (field.max !== undefined) validators.push(Validators.max(field.max));
  return validators;
}

function assertValidField(field: FormField): void {
  if (field.kind === 'select' && (field.options ?? []).length === 0) {
    throw new InvalidFormField(field.id, 'a select needs at least one option');
  }
}

function emptyValue(field: FormField): FieldValue {
  if (field.kind === 'number') return null;
  if (field.kind === 'multiselect' || field.kind === 'picks') return [];
  return field.kind === 'checkbox' ? false : '';
}

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
      controls[field.id] = typesText(field)
        ? typedTextControl(start, validatorsFor(field))
        : new FormControl<FieldValue>(start, { validators: validatorsFor(field) });
    }
  }
  for (const field of descriptor.sections.flatMap((section) => section.fields)) {
    for (let condition = field.visibleWhen; condition !== undefined; condition = condition.and) {
      if (!Object.hasOwn(controls, condition.field)) {
        throw new InvalidFormField(
          field.id,
          `its condition names "${condition.field}", which this form does not declare`,
        );
      }
    }
  }
  return new FormGroup(controls);
}

/**
 * Each control's value as the person typed it, ends included: what a form rebuilt over the one being typed in starts
 * from, so a space typed before the next word survives the rebuild. A save reads `getRawValue()` instead.
 */
export function valuesAsTyped(form: DescriptorFormGroup): FormValues {
  return Object.fromEntries(
    Object.entries(form.controls).map(([id, control]) => [id, control.value]),
  );
}

/** The one message a person sees under a field, most fundamental problem first; null when the value is valid. */
export function fieldError(control: AbstractControl, field: FormField): FieldError | null {
  const errors: ValidationErrors | null = control.errors;
  if (!errors) return null;
  // What the API refused outranks the form's own rules: it is what the last save was told.
  if (errors['refused']) return errors['refused'] as FieldError;
  if (errors['required']) return { key: 'form.errors.required' };
  // The field's own sentence names the format and an example, which the browser's word for an address does not.
  if (errors['pattern'] && field.patternError) return { key: field.patternError };
  if (errors['email']) return { key: 'form.errors.email' };
  if (errors['date']) return { key: 'form.errors.date' };
  if (errors['colour']) return { key: 'form.errors.colour' };
  if (errors['option']) return { key: 'form.errors.option' };
  if (errors['pattern'])
    return field.hint
      ? { key: 'form.errors.pattern', hint: field.hint }
      : { key: 'form.errors.pattern' };
  if (errors['minlength'])
    return { key: 'form.errors.min_length', params: { min: field.minLength! } };
  if (errors['maxlength']) return lengthSaidOf(control);
  if (errors['wholeNumber']) return { key: 'import.rejections.not_a_whole_number' };
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
