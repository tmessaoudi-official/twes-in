// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import { composeCode } from './barcode-reader';

describe('composeCode', () => {
  it('types a GS1 label as a GS1 scanner does: its symbology identifier, then its data with the separators', () => {
    expect(
      composeCode({
        text: '0113017620422000\u001d10LOT-7',
        symbologyIdentifier: ']C1',
        contentType: 'GS1',
        isValid: true,
      }),
    ).toBe(']C10113017620422000\u001d10LOT-7');
  });

  it('types any other code as its text alone', () => {
    expect(
      composeCode({
        text: '3017620422003',
        symbologyIdentifier: ']E0',
        contentType: 'Text',
        isValid: true,
      }),
    ).toBe('3017620422003');
  });

  it('types nothing for a symbol that did not decode', () => {
    expect(
      composeCode({
        text: '301762',
        symbologyIdentifier: ']E0',
        contentType: 'Text',
        isValid: false,
      }),
    ).toBeNull();
    expect(
      composeCode({ text: '', symbologyIdentifier: ']Q1', contentType: 'Text', isValid: true }),
    ).toBeNull();
  });
});
