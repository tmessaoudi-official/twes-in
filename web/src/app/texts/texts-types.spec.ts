// SPDX-License-Identifier: AGPL-3.0-or-later

import { marksOf, placeOf } from './texts-types';

describe('placeOf', () => {
  it('reads a page’s own texts as a screen, and every other domain as its own place', () => {
    expect(placeOf('screen')).toBe('screen');
    expect(placeOf('screen:two-factor')).toBe('screen');
    expect(placeOf('emails')).toBe('emails');
    expect(placeOf('pdf')).toBe('pdf');
    expect(placeOf('fiscal')).toBeNull();
  });
});

describe('marksOf', () => {
  it('finds the marks a screen text keeps, each once', () => {
    expect(marksOf('Bonjour, {{name}} ; {{ name }} et {{count}}', 'screen:account')).toEqual([
      '{{name}}',
      '{{ name }}',
      '{{count}}',
    ]);
  });

  it('finds the marks a mail or a document keeps, and none of the screens’ there', () => {
    expect(marksOf('%company% vous invite sur %product% {{name}}', 'emails')).toEqual([
      '%company%',
      '%product%',
    ]);
    expect(marksOf('Facture', 'pdf')).toEqual([]);
  });
});
