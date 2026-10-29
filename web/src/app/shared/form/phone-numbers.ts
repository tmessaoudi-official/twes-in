// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  AsYouType,
  type CountryCode,
  getCountries,
  getCountryCallingCode,
  getExampleNumber,
  parsePhoneNumberFromString,
} from 'libphonenumber-js/max';
import examples from 'libphonenumber-js/mobile/examples';
import metadata from 'libphonenumber-js/max/metadata';
import { countryFlag } from '../i18n/country-names';
import type { FieldError } from './form-builder';
import { typedText } from './typed-text';

/**
 * The one phone rule of the screens: a country picked beside the local number, written out in international form
 * (« +216 71 234 567 », its groups kept apart for reading), read back into the two when it parses. A number is judged
 * here as the API's `LibPhoneNumbers` judges it, by each country's full plan (libphonenumber-js's « max » metadata, as
 * the API reads Google's whole plan), and refused as `TypedPhones` refuses it; one of the right length the plan has not
 * handed out is the API's to keep with a doubt. Its own file, so the numbering plans load with the forms that use them,
 * in a chunk of their own, never with the first page.
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

/** What the local box holds after an edit, and where its caret stands. */
export interface PhoneEdit {
  readonly text: string;
  readonly caret: number;
}

/** What a person types into a phone: digits, a leading « + », the signs between groups, spaces and invisible marks. */
const AS_TYPED = /^[+0-9 ().\-/\p{Z}\p{Cf}]*$/u;

/** The signs that make the number: its digits and its « + »; the rest is how it is grouped. */
const significant = (text: string): string => text.replace(/[^+0-9]/g, '');

/** Where the caret stands after the given count of the number's own signs. */
function caretAfter(text: string, count: number): number {
  if (count === 0) return 0;
  let seen = 0;
  for (let at = 0; at < text.length; at++) {
    if (/[+0-9]/.test(text[at]!) && ++seen === count) return at + 1;
  }
  return text.length;
}

/**
 * The local box after an edit (ruled 2026-10-29): the digits grouped as the country picked writes them (libphonenumber's
 * AsYouType), a paste the same, and the caret after the same digit it followed. A space deleted takes the digit beside
 * it, or the grouping would put the space back under the caret at once. What holds anything else, a letter, is left as
 * typed, so the field says what is wrong with it rather than dropping it.
 */
export function phoneEdited(
  country: string,
  previous: string,
  typed: string,
  caret: number,
  inputType: string,
): PhoneEdit {
  if (!AS_TYPED.test(typed) || !/[0-9]/.test(typed)) return { text: typed, caret };
  let signs = significant(typed);
  let before = significant(typed.slice(0, caret)).length;
  if (inputType.startsWith('delete') && typed !== previous && signs === significant(previous)) {
    if (inputType === 'deleteContentForward') {
      signs = signs.slice(0, before) + signs.slice(before + 1);
    } else if (before > 0) {
      signs = signs.slice(0, before - 1) + signs.slice(before);
      before -= 1;
    }
  }
  const text = new AsYouType(isCountry(country) ? country : undefined).input(signs);
  return { text, caret: caretAfter(text, before) };
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

/** The API's verdicts on a phone (`PhoneVerdict`), in its own words. */
export type PhoneVerdictKind =
  'valid' | 'to_check' | 'wrong_length' | 'needs_country' | 'not_a_number';

/** A phone judged: its verdict, and the number kept in E.164 for a valid one or one to check. */
export interface PhoneVerdict {
  readonly verdict: PhoneVerdictKind;
  readonly e164: string | null;
}

/** Only the digits, a leading « + », and what a person puts between the groups, as the API's `LibPhoneNumbers` reads. */
const WRITTEN = /^\+?[0-9 ().\-/]+$/;

/**
 * A phone judged as the API's `LibPhoneNumbers::judge` judges it, by its country's full numbering plan: read by the
 * country given unless « + » or « 00 » names another; refused for no number, no country to read it by, or a wrong
 * length; valid, or to check when its length is right but the country hands out no such range. phone-vectors.json holds
 * the two to the same answers.
 */
export function phoneVerdict(typed: string, country: string | null): PhoneVerdict {
  const written = plain(typed)
    .replace(/[\p{Z}\p{Cc}]+/gu, ' ')
    .trim()
    .replace(/^00/, '+');
  if (written === '' || !WRITTEN.test(written) || !/[0-9]/.test(written)) {
    return { verdict: 'not_a_number', e164: null };
  }
  const region = country !== null && isCountry(country) ? country : undefined;
  if (!written.startsWith('+') && region === undefined) {
    return { verdict: 'needs_country', e164: null };
  }
  const parsed = parsePhoneNumberFromString(written, region);
  if (parsed === undefined) return { verdict: 'not_a_number', e164: null };
  if (!parsed.isPossible()) return { verdict: 'wrong_length', e164: null };
  return { verdict: parsed.isValid() ? 'valid' : 'to_check', e164: parsed.number };
}

/** What a phone field's value is refused with, the API's own refusals; null for a good number or no number. */
export function phoneRefusal(value: string): FieldError | null {
  const typed = plain(value);
  if (typed === '') return null;
  // A value is international by now, so a country is named by its own code, never by the field's.
  const { verdict } = phoneVerdict(typed, null);
  if (verdict === 'valid' || verdict === 'to_check') return null;
  if (verdict === 'needs_country') return { key: 'import.rejections.phone_country' };
  if (verdict === 'not_a_number') return { key: 'import.rejections.invalid_phone' };
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
