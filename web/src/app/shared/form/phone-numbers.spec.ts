// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  phoneCountries,
  phoneEdited,
  phoneRefusal,
  phoneValue,
  readPhone,
  samePhone,
} from './phone-numbers';

/**
 * The one phone rule of the screens: a country beside the local number, written out in international form, read back
 * into the two when it parses, and refused for a wrong length only, as the API refuses it (`TypedPhones`).
 */
describe('phone numbers', () => {
  it('writes the local number in international form, the country code first, its groups kept apart', () => {
    expect(phoneValue('TN', '71 234 567')).toBe('+216 71 234 567');
    expect(phoneValue('TN', '71234567')).toBe('+216 71 234 567');
    expect(phoneValue('FR', '06 12 34 56 78')).toBe('+33 6 12 34 56 78');
  });

  it('lets a number written with + or 00 choose its own country over the one picked', () => {
    expect(phoneValue('TN', '+33 6 12 34 56 78')).toBe('+33 6 12 34 56 78');
    expect(phoneValue('TN', '0033 6 12 34 56 78')).toBe('+33 6 12 34 56 78');
  });

  it('keeps the country code before a number it cannot read yet, so the country is not lost', () => {
    expect(phoneValue('FR', '12')).toBe('+33 12');
    expect(phoneValue('FR', '1')).toBe('+33 1');
    expect(phoneValue('TN', 'abc')).toBe('+216 abc');
    expect(phoneValue('TN', '')).toBe('');
    expect(phoneValue('TN', '  ')).toBe('');
  });

  it('reads a kept number back into its country and its local part', () => {
    expect(readPhone('+21671234567', 'FR')).toEqual({ country: 'TN', local: '71 234 567' });
    // As the country writes it at home, its trunk 0 included, which reads back to the same number.
    expect(readPhone('+33 6 12 34 56 78', 'TN')).toEqual({
      country: 'FR',
      local: '06 12 34 56 78',
    });
  });

  it('recovers the country of a number half typed from its code', () => {
    expect(readPhone('+33 12', 'TN')).toEqual({ country: 'FR', local: '12' });
  });

  it('shows a number kept from before the rule as it was saved, under the default country', () => {
    expect(readPhone('71 234 567', 'TN')).toEqual({ country: 'TN', local: '71 234 567' });
    expect(readPhone('', 'FR')).toEqual({ country: 'FR', local: '' });
  });

  it('refuses a number of the wrong length for its country, with an example of that country', () => {
    const refused = phoneRefusal('+216 71 234', 'refuse');

    expect(refused?.key).toBe('import.rejections.phone_length');
    expect(refused?.params?.['example']).toMatch(/^\+216 /);
  });

  it('refuses what is no number, and takes a good number or nothing', () => {
    expect(phoneRefusal('+216 abc', 'refuse')?.key).toBe('import.rejections.invalid_phone');
    expect(phoneRefusal('+216 ------', 'refuse')?.key).toBe('import.rejections.invalid_phone');
    expect(phoneRefusal('+216 71 234 567', 'refuse')).toBeNull();
    expect(phoneRefusal('', 'refuse')).toBeNull();
  });

  // The platform's policy (ruled 2026-10-29), which the API's TypedPhones reads too: « Refuser » refuses a number of the
  // right length outside the ranges its country hands out, with that country's example; « Garder et marquer » takes it.
  it("refuses a number outside its country's ranges with its example, unless the platform keeps them", () => {
    const refused = phoneRefusal('+216 80 123 456', 'refuse');

    expect(refused?.key).toBe('import.rejections.phone_outside_ranges');
    expect(refused?.params?.['example']).toMatch(/^\+216 /);
    expect(phoneRefusal('+216 80 123 456', 'keep')).toBeNull();
    expect(phoneRefusal('+216 71 234', 'keep')?.key).toBe('import.rejections.phone_length');
  });

  // Grouped as typed (ruled 2026-10-29): the digits in the country's own groups, the caret after the same digit it
  // followed, a paste the same; what holds a letter is left as typed, so the field can say what is wrong with it.
  it('groups the digits as the country writes them while they are typed, the caret after the same digit', () => {
    expect(phoneEdited('TN', '', '71234567', 8, 'insertText')).toEqual({
      text: '71 234 567',
      caret: 10,
    });
    expect(phoneEdited('FR', '06 12', '06 123', 6, 'insertText')).toEqual({
      text: '06 12 3',
      caret: 7,
    });
    // A digit typed in the middle: the caret stays after it, however the groups moved.
    expect(phoneEdited('FR', '06 12 34 56', '06 172 34 56', 5, 'insertText')).toEqual({
      text: '06 17 23 45 6',
      caret: 5,
    });
    expect(phoneEdited('TN', '', '+33612345678', 12, 'insertText')).toEqual({
      text: '+33 6 12 34 56 78',
      caret: 17,
    });
  });

  it('groups a paste the same, whatever signs it carried between its digits', () => {
    expect(phoneEdited('TN', '', '(71) 234-567', 12, 'insertFromPaste')).toEqual({
      text: '71 234 567',
      caret: 10,
    });
    expect(phoneEdited('TN', '', '71 234 567', 10, 'insertFromPaste').text).toBe('71 234 567');
  });

  it('takes the digit before a space deleted, rather than putting the space back under the caret', () => {
    expect(phoneEdited('FR', '06 12 34', '0612 34', 2, 'deleteContentBackward')).toEqual({
      text: '01 23 4',
      caret: 1,
    });
    expect(phoneEdited('FR', '06 12 34', '06 1234', 5, 'deleteContentForward')).toEqual({
      text: '06 12 4',
      caret: 5,
    });
  });

  it('leaves what holds a letter as typed', () => {
    expect(phoneEdited('TN', '71 900 868', '71 900 868 p', 12, 'insertText')).toEqual({
      text: '71 900 868 p',
      caret: 12,
    });
    expect(phoneEdited('TN', '', 'abc', 3, 'insertText')).toEqual({ text: 'abc', caret: 3 });
  });

  it('tells the same number written two ways', () => {
    expect(samePhone('+21671234567', '+216 71 234 567')).toBe(true);
    expect(samePhone('+21671234567', '+216 71 234 568')).toBe(false);
    expect(samePhone('71 234 567', '+216 71 234 567')).toBe(false);
  });

  it('names every country in the screen language, by name order, with its calling code', () => {
    const french = phoneCountries('fr');
    const tunisia = french.find((country) => country.code === 'TN');

    expect(tunisia).toMatchObject({ callingCode: '216', name: 'Tunisie' });
    expect(tunisia?.flag).toBe('\u{1F1F9}\u{1F1F3}');
    expect(phoneCountries('ar').find((country) => country.code === 'TN')?.name).toBe('تونس');
    const names = french.map((country) => country.name);
    expect(names).toEqual([...names].sort((a, b) => a.localeCompare(b, 'fr')));
    expect(french.every((country) => country.name !== '' && country.name !== country.code)).toBe(
      true,
    );
    expect(french.length).toBeGreaterThan(200);
  });
});
