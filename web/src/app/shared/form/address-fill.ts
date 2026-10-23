// SPDX-License-Identifier: AGPL-3.0-or-later

import type { FormGroup } from '@angular/forms';

/**
 * Which field of a form each part of an address goes to, for an address another record already holds and a form
 * offers to copy where the two are related: a delivery note from its customer, an establishment from its company.
 */
export type AddressFields<T> = Readonly<Record<keyof T & string, string>>;

/**
 * Copies an address into the fields of a form, part by part. Every field takes the source's part, an empty one
 * included, so what shows is that address and no mix of two; each stays editable and unsaved until the form is saved.
 */
export function fillAddress<T extends object>(
  form: FormGroup,
  fields: AddressFields<T>,
  source: T,
): void {
  for (const part of Object.keys(fields) as (keyof T & string)[]) {
    const control = form.controls[fields[part]];
    if (control === undefined) continue;
    control.setValue(String(source[part] ?? ''));
    control.markAsDirty();
  }
}

/** Whether a source holds any part at all: copying nothing is never offered. */
export function holdsAddress<T extends object>(
  source: T | null,
  fields: AddressFields<T>,
): boolean {
  return (
    source !== null &&
    (Object.keys(fields) as (keyof T & string)[]).some(
      (part) => String(source[part] ?? '').trim() !== '',
    )
  );
}
