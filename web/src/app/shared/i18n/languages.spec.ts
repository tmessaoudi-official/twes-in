// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  DEFAULT_LANGUAGE,
  directionOf,
  INTERFACE_LANGUAGES,
  LANGUAGES,
  LEGAL_LANGUAGES,
  nameOf,
} from './languages';

describe('the language catalogue', () => {
  it('names each language by a canonical BCP 47 tag, once', () => {
    const tags = LANGUAGES.map((language) => language.tag);
    expect(new Set(tags).size).toBe(tags.length);
    for (const tag of tags) {
      expect(Intl.getCanonicalLocales(tag)).toEqual([tag]);
    }
  });

  it('holds the plural forms each language really has, as the browser counts them', () => {
    for (const language of LANGUAGES) {
      const counted = new Intl.PluralRules(language.tag).resolvedOptions().pluralCategories;
      expect([...language.plurals].sort(), language.tag).toEqual([...counted].sort());
    }
  });

  it('holds the script and direction the language is written in', () => {
    for (const language of LANGUAGES) {
      expect(new Intl.Locale(language.tag).maximize().script, language.tag).toBe(language.script);
    }
    expect(directionOf('ar')).toBe('rtl');
    expect(directionOf('fr')).toBe('ltr');
  });

  it('offers on screen the languages whose texts ship, the default first', () => {
    expect(INTERFACE_LANGUAGES).toEqual(['fr', 'en']);
    expect(INTERFACE_LANGUAGES[0]).toBe(DEFAULT_LANGUAGE);
    expect(LEGAL_LANGUAGES).toEqual(['fr', 'en', 'ar']);
  });

  it('names each language in itself, never in the language on screen', () => {
    expect(nameOf('fr')).toBe('Français');
    expect(nameOf('ar')).toBe('العربية');
  });
});
