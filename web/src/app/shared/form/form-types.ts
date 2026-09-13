// SPDX-License-Identifier: AGPL-3.0-or-later

export type FieldKind = 'text' | 'email' | 'tel' | 'number' | 'date' | 'textarea' | 'select';

export type FieldValue = string | number | null;

export interface FieldOption {
  value: string;
  /** A translation key for declared options; custom options carry their configured label. */
  label: string;
}

/**
 * One field of a form, as configuration: a screen declares its fields, an installation may add custom ones
 * (withCustomFields), and the validators here are the whole of what the form enforces on the client. The API
 * validates again; these exist so a person learns about a mistake next to the field, before submitting.
 */
export interface FormField {
  id: string;
  /** A translation key for declared fields; custom fields carry their configured label. */
  label: string;
  kind: FieldKind;
  required?: boolean;
  minLength?: number;
  maxLength?: number;
  min?: number;
  max?: number;
  /** A regular expression the whole value must match. */
  pattern?: string;
  /** Required for `select`. */
  options?: FieldOption[];
  hint?: string;
  /** How many of the section's two grid columns the field spans on a wide screen; phones always use one. */
  span?: 1 | 2;
  defaultValue?: FieldValue;
  autocomplete?: string;
}

export interface FormSection {
  id: string;
  /** A translation key for declared sections. */
  title: string;
  fields: FormField[];
}

export interface FormDescriptor {
  id: string;
  sections: FormSection[];
}

export type FormValues = Record<string, FieldValue>;
