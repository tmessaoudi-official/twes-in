// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  type CountryCode,
  getCountries,
  getCountryCallingCode,
  getExampleNumber,
  parsePhoneNumberFromString,
  validatePhoneNumberLength,
} from 'libphonenumber-js';
import examples from 'libphonenumber-js/mobile/examples';
import metadata from 'libphonenumber-js/min/metadata';
import { countryFlag } from '../i18n/country-names';
import type { FieldError } from './form-builder';
import { typedText } from './typed-text';

/**
 * The one phone rule of the screens: a country picked beside the local number, written out in international form
 * (« +216 71 234 567 », its groups kept apart for reading), read back into the two when it parses. A number is refused
 * here for its length only, as the API's `TypedPhones` refuses it; one of the right length the numbering plan has not
 * handed out is the API's to keep with a doubt. Its own file, so the numbering plans load with the forms that use them.
 */

/** One country a phone may be read by: its code, its calling code, its name in the screen's language and its flag. */
export interface PhoneCountry {
  readonly code: string;
  readonly callingCode: string;
  readonly name: string;
  readonly flag: string;
}

/** What a phone field holds once read: the country picked and the number as the person reads it in that country. */
export interface ReadPhone {
  readonly country: string;
  readonly local: string;
}

const isCountry = (code: string): code is CountryCode =>
  (getCountries() as readonly string[]).includes(code);

/**
 * What was typed with every space plain and no invisible mark: a number copied from a hint, a refusal or a printed
 * document keeps its groups apart with a narrow no-break space, which the numbering plans do not read as one.
 */
function plain(text: string): string {
  return typedText(text)
    .replace(/\p{Cf}/gu, '')
    .replace(/\p{Zs}/gu, ' ');
}

/** « 00 » before a country code is the same as « + », as a person dialling from abroad writes it. */
function international(typed: string): string | null {
  if (typed.startsWith('+')) return typed;
  return typed.startsWith('00') ? `+${typed.slice(2)}` : null;
}

/** The value a phone field hands its form: international, or empty for no phone. */
export function phoneValue(country: string, local: string): string {
  const typed = plain(local);
  if (typed === '') return '';
  const written = international(typed);
  if (written !== null)
    return parsePhoneNumberFromString(written)?.formatInternational() ?? written;
  if (!isCountry(country)) return typed;
  const parsed = parsePhoneNumberFromString(typed, country);
  // A number too short to read keeps its country code before it, so a form rebuilt over it keeps the country picked.
  return parsed !== undefined
    ? parsed.formatInternational()
    : `+${getCountryCallingCode(country)} ${typed}`;
}

/** The country a calling code is read as: the default one where it shares the code, else the code's main country. */
function countryOfCode(digits: string, fallback: string): { country: string; code: string } | null {
  const codes = metadata.country_calling_codes as Record<string, readonly string[]>;
  for (let length = 1; length <= 3; length++) {
    const code = digits.slice(0, length);
    const countries = codes[code];
    if (countries !== undefined && countries.length > 0) {
      return { country: countries.includes(fallback) ? fallback : countries[0]!, code };
    }
  }
  return null;
}

/**
 * A kept value read into the country and the local number: one that parses into its country and number, one half typed
 * into the country its code names; one kept from before the rule, with no « + », shown as it was saved.
 */
export function readPhone(value: string, fallback: string): ReadPhone {
  const typed = plain(value);
  const written = international(typed);
  if (written === null) return { country: fallback, local: typed };
  const parsed = parsePhoneNumberFromString(written);
  if (parsed?.country !== undefined) {
    return { country: parsed.country, local: parsed.formatNational() };
  }
  const digits = written.slice(1).replace(/\D/g, '');
  const read = countryOfCode(digits, fallback);
  if (read === null) return { country: fallback, local: typed };
  const rest = written.slice(1).replace(/^\D*/, '');
  return { country: read.country, local: typedText(rest.slice(read.code.length)) };
}

/** What a phone field's value is refused with, the API's own refusals; null for a good number or no number. */
export function phoneRefusal(value: string): FieldError | null {
  const typed = plain(value);
  if (typed === '') return null;
  // Signs with no digit after the country code are no number at all, whatever length the code alone would be.
  const number = typed.replace(/^\+[0-9]{1,3}\s/, '');
  if (!/[0-9]/.test(number)) return { key: 'import.rejections.invalid_phone' };
  const verdict = validatePhoneNumberLength(typed);
  if (verdict === undefined) return null;
  if (verdict === 'TOO_SHORT' || verdict === 'TOO_LONG' || verdict === 'INVALID_LENGTH') {
    const country =
      parsePhoneNumberFromString(typed)?.country ??
      countryOfCode(typed.replace(/\D/g, ''), '')?.country;
    const example =
      country !== undefined && isCountry(country)
        ? getExampleNumber(country, examples)?.formatInternational()
        : undefined;
    // A code no country answers to is said as the country missing, whose sentence carries its own example.
    return example === undefined
      ? { key: 'import.rejections.phone_country' }
      : { key: 'import.rejections.phone_length', params: { example } };
  }
  if (verdict === 'INVALID_COUNTRY') return { key: 'import.rejections.phone_country' };
  return { key: 'import.rejections.invalid_phone' };
}

/**
 * A number of a country as a person there types it without the code (« 20 123 456 », « 06 12 34 56 78 »): what the
 * local box asks for, offered under it. The numbering plans' own example, never one of ours; empty for no country.
 */
export function phoneExample(country: string): string {
  return isCountry(country) ? (getExampleNumber(country, examples)?.formatNational() ?? '') : '';
}

/** Whether two values name the same number, however each was written; a value that does not parse names none. */
export function samePhone(a: string, b: string): boolean {
  const first = international(plain(a));
  const second = international(plain(b));
  if (first === null || second === null) return false;
  const left = parsePhoneNumberFromString(first)?.number;
  return left !== undefined && left === parsePhoneNumberFromString(second)?.number;
}

/**
 * Every country a phone may belong to, named in the screen's language and in that name's order; the device's own
 * language before the screen's texts have arrived.
 */
export function phoneCountries(language: string): readonly PhoneCountry[] {
  const locales = language === '' ? [] : [language];
  const names = new Intl.DisplayNames(locales, { type: 'region', fallback: 'none' });
  return getCountries()
    .flatMap((code) => {
      // A territory with no name of its own (Ascension, Tristan da Cunha) is reached through its main country's code.
      const name = names.of(code);
      return name === undefined || name === code
        ? []
        : [{ code, callingCode: getCountryCallingCode(code), name, flag: countryFlag(code) }];
    })
    .sort((a, b) => a.name.localeCompare(b.name, locales));
}
