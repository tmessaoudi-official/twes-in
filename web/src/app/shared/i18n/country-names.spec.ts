// SPDX-License-Identifier: AGPL-3.0-or-later

import { countriesNamed, countryFlag, countryName } from './country-names';

// One way to name a country everywhere (ruled 2026-10-29): its flag and the browser's own name for it, in the screen's
// language, with no text of ours per country.
describe('country names', () => {
  it('draws a country as its flag and its name in the screen language', () => {
    expect(countryFlag('TN')).toBe('\u{1F1F9}\u{1F1F3}');
    expect(countryName('TN', 'fr')).toBe('\u{1F1F9}\u{1F1F3} Tunisie');
    expect(countryName('FR', 'en')).toBe('\u{1F1EB}\u{1F1F7} France');
    expect(countryName('MA', 'ar')).toContain('المغرب');
  });

  it('names a country the browser has no name for by its code alone, and never throws on what is no code', () => {
    expect(countryName('tunisie', 'fr')).toBe('tunisie');
    expect(countryName('', 'fr')).toBe('');
  });

  it("lists countries in the order of their names in the screen's language, as the phone picker does", () => {
    expect(countriesNamed(['EG', 'DE'], 'fr')).toEqual([
      { code: 'DE', label: '\u{1F1E9}\u{1F1EA} Allemagne' },
      { code: 'EG', label: '\u{1F1EA}\u{1F1EC} Égypte' },
    ]);
    expect(countriesNamed(['DE', 'EG'], 'en').map((each) => each.code)).toEqual(['EG', 'DE']);
  });

  it("names it in the device's language before the screen's is known", () => {
    expect(countryName('TN', '')).toContain('\u{1F1F9}\u{1F1F3} ');
  });
});
