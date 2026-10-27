// SPDX-License-Identifier: AGPL-3.0-or-later

export type FieldKind =
  | 'text'
  | 'email'
  | 'tel'
  /** A web address, typed with the keyboard a phone offers for one. */
  | 'url'
  | 'number'
  /** An amount, a quantity or a rate as the API writes it ("890.000"), shown and typed the locale's way. */
  | 'decimal'
  | 'date'
  | 'textarea'
  | 'select'
  /** Several of a select's options, kept as a list of their values in the order they are offered. */
  | 'multiselect'
  /**
   * One record out of a book too long to offer whole: the form asks the API for the few that match what is typed
   * (docs/SPEC.md § 7, 2026-09-17, ruling 3). The control holds the chosen record's id, as a select's would; where
   * the rows come from is not in the descriptor, because a descriptor is data — the screen passes a `pickers` map
   * to `DescriptorForm` beside it.
   */
  | 'pick'
  /**
   * Several records out of such a book: the ones held, each with its button to take it off, then a box asking the API
   * for one more. The control holds their ids in the order they were added.
   */
  | 'picks'
  | 'checkbox'
  /** A `#rrggbb` colour, picked with the browser's colour control. */
  | 'colour';

export type FieldValue = string | number | boolean | readonly string[] | null;

export interface FieldOption {
  value: string;
  /** A translation key for declared options; custom options carry their configured label. */
  label: string;
}

/**
 * Another field of the same form, and the values of it under which a field applies: one of a list, or, for a decimal
 * field, any writing of nothing (`0`, `0.000`), which no list of values can name.
 */
export type FieldCondition = {
  field: string;
  /** A second condition that must hold too: a choice hidden by its own condition keeps its value, not its say. */
  and?: FieldCondition;
} & ({ oneOf: string[]; zero?: never } | { zero: true; oneOf?: never });

/**
 * One field of a form, as configuration: a screen declares its fields, an installation may add custom ones
 * (withCustomFields), and the validators here are the whole of what the form enforces on the client. The API
 * validates again; these exist so a person learns about a mistake next to the field, before submitting.
 */
export interface FormField {
  id: string;
  /** A translation key for declared fields; custom fields carry their configured label. */
  label: string;
  /** What fills the label's placeholders, such as the language a name is written in. */
  labelParams?: Record<string, string>;
  /**
   * The name of the button emptying a field the screen lets go (`DescriptorForm.removable`), read with `labelParams`:
   * « Retirer le nom en arabe ». Only a text, number or decimal field shows it.
   */
  removeLabel?: string;
  kind: FieldKind;
  /** For a checkbox, required means it must be ticked. */
  required?: boolean;
  minLength?: number;
  maxLength?: number;
  min?: number;
  max?: number;
  /** A regular expression the whole value must match. */
  pattern?: string;
  /**
   * What a value outside `pattern` is told: its format, an example and what to do, the very sentence the API
   * refuses it with (`import.rejections.*`), since the browser's check answers first.
   */
  patternError?: string;
  /** Required for `select`; the choices of a `multiselect`. */
  options?: FieldOption[];
  /** A select whose options are language tags: the dropdown names each language itself (`Select.languages`). */
  languages?: boolean;
  /** For a `pick` that may name nothing: what that answer reads as, and what an empty answer reads as. */
  noneLabel?: string;
  noneFoundLabel?: string;
  hint?: string;
  /** What the hint names, read from data rather than written in its words: a rate, a threshold. */
  hintParams?: Record<string, string>;
  /** How many of the section's two grid columns the field spans on a wide screen; phones always use one. */
  span?: 1 | 2;
  defaultValue?: FieldValue;
  autocomplete?: string;
  /**
   * Shown, validated and submitted only while another field holds one of these values: a stamp has an amount, a
   * VAT rate has a rate. A hidden field keeps what was typed in it, in case the person switches back.
   */
  visibleWhen?: FieldCondition;
  /** Shown and submitted as it stands, never changed here: a code numbered documents already carry. */
  readOnly?: boolean;
}

export interface FormSection {
  id: string;
  /** A translation key for declared sections. */
  title: string;
  /** A translation key: what the section is for, shown beside its title. */
  description?: string;
  fields: FormField[];
}

export interface FormDescriptor {
  id: string;
  sections: FormSection[];
}

export type FormValues = Record<string, FieldValue>;
