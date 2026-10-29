// SPDX-License-Identifier: AGPL-3.0-or-later

const ISO_COUNTRY = /^[A-Z]{2}$/;

/** A country's flag, the two regional indicator symbols its ISO 3166 code spells. */
export function countryFlag(code: string): string {
  return String.fromCodePoint(...[...code].map((letter) => 0x1f1e6 + letter.charCodeAt(0) - 65));
}

/** The browser's own name for a country in the given language, the device's when none is given. */
function browserName(code: string, language: string): string {
  return (
    new Intl.DisplayNames(language === '' ? [] : [language], { type: 'region' }).of(code) ?? code
  );
}

/**
 * A country named the one way every screen names it: its flag and the browser's own name for it in the screen's
 * language, with no text of ours per country. Before the screen's language is known, the device's. What is no ISO code
 * is shown as it came.
 */
export function countryName(code: string, language: string): string {
  return ISO_COUNTRY.test(code) ? `${countryFlag(code)} ${browserName(code, language)}` : code;
}

export interface CountryNamed {
  readonly code: string;
  readonly label: string;
}

/** Countries each named as {@link countryName} names it, in the order of their names in that language. */
export function countriesNamed(
  codes: readonly string[],
  language: string,
): readonly CountryNamed[] {
  const locales = language === '' ? [] : [language];
  return codes
    .map((code) => ({ code, name: ISO_COUNTRY.test(code) ? browserName(code, language) : code }))
    .sort((a, b) => a.name.localeCompare(b.name, locales))
    .map(({ code }) => ({ code, label: countryName(code, language) }));
}
