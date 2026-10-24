// SPDX-License-Identifier: AGPL-3.0-or-later

/** Whose wording a page writes: the installation's, which every company reads, or one company's own over it. */
export type TextLevel =
  { readonly kind: 'installation' } | { readonly kind: 'company'; readonly companyId: string };

/** One text a writer may word otherwise, as the catalogue answers it. */
export interface TextRow {
  readonly domain: string;
  readonly key: string;
  /** The text this release ships. */
  readonly original: string;
  readonly installation: string | null;
  /** The company's own wording; always null on the installation's page. */
  readonly company: string | null;
  /** The version of the writer's own level, 0 where it words nothing. */
  readonly version: number;
  /** The writer's own wording was written over another original than this release ships. */
  readonly originalChanged: boolean;
}

/** What the catalogue is asked for: the words looked for, one place, only the texts whose original changed, a page. */
export interface TextQuery {
  readonly words: string;
  readonly place: TextPlace | null;
  readonly changed: boolean;
  /** From 1. */
  readonly page: number;
  readonly itemsPerPage: number;
}

export interface TextPage {
  readonly rows: readonly TextRow[];
  readonly total: number;
}

/** What a writer's level keeps of a text once saved. */
export interface SavedText {
  readonly value: string;
  readonly version: number;
}

/**
 * Where a text is read, as the catalogue's domains group them: the screens (the language's file and every page's own),
 * the mails, the printed documents, an import's notes and the roles' names. The fiscal texts are the law's, never listed.
 */
export const TEXT_PLACES = ['screen', 'emails', 'pdf', 'imports', 'roles'] as const;
export type TextPlace = (typeof TEXT_PLACES)[number];

/** The place a catalogue domain belongs to: `screen:<page>` is a screen; one unknown here is null. */
export function placeOf(domain: string): TextPlace | null {
  const group = domain.split(':')[0];
  return TEXT_PLACES.find((place) => place === group) ?? null;
}

/** Why a text was not kept, each said by the screen beside the field (`texts.refusals.<code>`). */
export const TEXT_REFUSALS = [
  'empty',
  'too_long',
  'placeholders',
  'plural',
  'locked',
  'unknown_text',
  'language',
  'changed',
  'unavailable',
] as const;
export type TextRefusalCode = (typeof TEXT_REFUSALS)[number];

/** A text the API did not keep, and why. */
export class TextRefused extends Error {
  constructor(readonly code: TextRefusalCode) {
    super(`The text was refused: ${code}.`);
  }
}

/**
 * The marks a text must keep as the original writes them, `{{name}}` on a screen and `%name%` in a mail or a document,
 * each once, read as the API reads them (`OverrideRules`): a wording that drops or adds one is refused.
 */
export function marksOf(original: string, domain: string): readonly string[] {
  const marks = placeOf(domain) === 'screen' ? /\{\{\s?[^{}\s]*\s?\}\}/g : /%[A-Za-z_]\w*%/g;
  return [...new Set(original.match(marks) ?? [])];
}
