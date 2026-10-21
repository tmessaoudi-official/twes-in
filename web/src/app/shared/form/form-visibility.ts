// SPDX-License-Identifier: AGPL-3.0-or-later

import type { DescriptorFormGroup } from './form-builder';
import type { FieldCondition, FormDescriptor, FormField, FormValues } from './form-types';

/** Whether a field applies given the form's current values: always, unless its condition says otherwise. */
export function fieldApplies(field: FormField, values: FormValues): boolean {
  return field.visibleWhen === undefined || holds(field.visibleWhen, values);
}

function holds(condition: FieldCondition, values: FormValues): boolean {
  const value = values[condition.field];
  return (
    value !== null &&
    value !== undefined &&
    (condition.zero === true
      ? ZERO.test(String(value))
      : condition.oneOf.includes(String(value))) &&
    (condition.and === undefined || holds(condition.and, values))
  );
}

/** A decimal as the control keeps it, the API's point form, that comes to nothing; an empty field is no number at all. */
const ZERO = /^[+-]?(0+(\.0*)?|\.0+)$/;

/**
 * Enables the controls of the fields that apply and disables the others, so a hidden field is neither validated
 * nor submitted; a read-only form keeps every control disabled, and a read-only field keeps its own control disabled
 * while its value is still submitted. Runs without emitting, so calling it from a value-change subscription does not
 * loop.
 */
export function applyVisibility(
  descriptor: FormDescriptor,
  form: DescriptorFormGroup,
  readOnly = false,
): void {
  const values = form.getRawValue();
  for (const field of descriptor.sections.flatMap((section) => section.fields)) {
    const control = form.controls[field.id];
    if (!control) continue;
    const applies = !readOnly && field.readOnly !== true && fieldApplies(field, values);
    if (applies && control.disabled) control.enable({ emitEvent: false });
    if (!applies && control.enabled) control.disable({ emitEvent: false });
  }
}

/** The values of the fields that apply: what a submit hands to the screen. */
export function applicableValues(
  descriptor: FormDescriptor,
  form: DescriptorFormGroup,
): FormValues {
  const values = form.getRawValue();
  const out: FormValues = {};
  for (const field of descriptor.sections.flatMap((section) => section.fields)) {
    if (fieldApplies(field, values)) out[field.id] = values[field.id];
  }
  return out;
}
