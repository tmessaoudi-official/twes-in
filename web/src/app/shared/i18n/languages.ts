// SPDX-License-Identifier: AGPL-3.0-or-later

import catalogue from './languages.json';

/**
 * The languages twes-in knows, as data: adding one is an entry in `languages.json` (and its copy in
 * `api/config/`, which `scripts/gates/language-catalogue.sh` holds equal), its screen texts and, for a new
 * script, a font — never a code change. A tag is a canonical BCP 47 tag, as stored.
 */
export type Direction = 'ltr' | 'rtl';

export interface LanguageEntry {
  readonly tag: string;
  /** The language's name in itself, as a language menu shows it whatever language the screen is in. */
  readonly name: string;
  readonly direction: Direction;
  readonly script: string;
  /** Its CLDR plural categories, which a screen text's `{count, plural, …}` must cover. */
  readonly plurals: readonly Intl.LDMLPluralRule[];
  /** Its screen texts ship, so a person may choose it. */
  readonly interface: boolean;
  /** Documents may be printed in it. */
  readonly documents: boolean;
  /** The legal pages are written in it. */
  readonly legal: boolean;
}

/** A language tag; which ones are known is the catalogue's to say, never the type's. */
export type Language = string;

export const LANGUAGES: readonly LanguageEntry[] = catalogue.languages.map((entry) => ({
  ...entry,
  direction: entry.direction === 'rtl' ? 'rtl' : 'ltr',
  plurals: entry.plurals as Intl.LDMLPluralRule[],
}));

export const DEFAULT_LANGUAGE: Language = catalogue.default;

export const INTERFACE_LANGUAGES: readonly Language[] = tagsWhere((entry) => entry.interface);
export const DOCUMENT_LANGUAGES: readonly Language[] = tagsWhere((entry) => entry.documents);
export const LEGAL_LANGUAGES: readonly Language[] = tagsWhere((entry) => entry.legal);

export function languageEntry(tag: Language): LanguageEntry | undefined {
  return LANGUAGES.find((entry) => entry.tag === tag);
}

/** An unknown tag reads left to right, as the default does. */
export function directionOf(tag: Language): Direction {
  return languageEntry(tag)?.direction ?? 'ltr';
}

/** An unknown tag is shown as itself. */
export function nameOf(tag: Language): string {
  return languageEntry(tag)?.name ?? tag;
}

function tagsWhere(test: (entry: LanguageEntry) => boolean): Language[] {
  return LANGUAGES.filter(test).map((entry) => entry.tag);
}
