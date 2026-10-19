// SPDX-License-Identifier: AGPL-3.0-or-later

import { languageChoices, languageOption } from './language-options';

describe('languageOption', () => {
  it('names a language in itself, in its own lang, with its name in the screen language under it', () => {
    expect(languageOption('en', 'fr')).toEqual({
      label: 'English',
      lang: 'en',
      hint: 'Anglais',
      hintLang: 'fr',
    });
    expect(languageOption('ar', 'fr')).toEqual({
      label: 'العربية',
      lang: 'ar',
      hint: 'Arabe',
      hintLang: 'fr',
    });
  });

  it('writes the second line in whatever language the screen is in', () => {
    expect(languageOption('fr', 'ar')).toMatchObject({ hint: 'الفرنسية', hintLang: 'ar' });
    expect(languageOption('en', 'ar')).toMatchObject({ hint: 'الإنجليزية', hintLang: 'ar' });
  });

  it('draws no second line where it would repeat the name, whatever its case', () => {
    expect(languageOption('fr', 'fr')).toEqual({ label: 'Français', lang: 'fr' });
    expect(languageOption('en', 'en')).toEqual({ label: 'English', lang: 'en' });
  });

  it('shows a tag the catalogue does not know as itself, with the name the browser knows', () => {
    expect(languageOption('de', 'fr')).toMatchObject({ label: 'de', hint: 'Allemand' });
  });
});

describe('languageChoices', () => {
  it('offers each tag as a value, with the test id a page picks it by', () => {
    expect(languageChoices(['fr', 'ar'], 'pick')).toEqual([
      { value: 'fr', label: 'fr', testId: 'pick-fr' },
      { value: 'ar', label: 'ar', testId: 'pick-ar' },
    ]);
    expect(languageChoices(['fr'])).toEqual([{ value: 'fr', label: 'fr' }]);
  });
});
