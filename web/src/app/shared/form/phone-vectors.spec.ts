// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import { phoneVerdict } from './phone-numbers';
import vectors from './phone-vectors.json';

/**
 * The screen judges a phone as the API does, by each country's full numbering plan: every number in phone-vectors.json
 * is read here as `LibPhoneNumbers::judge` reads it in `PhoneVectorsTest`, to the same verdict and the same E.164. A
 * number of the right length outside the ranges a country hands out is the case only the full plans tell.
 */
describe('phone vectors', () => {
  it.each(vectors.map((vector) => [vector.typed, vector.country, vector]))(
    'reads « %s » (country %s) as the API does',
    (_typed, _country, vector) => {
      expect(phoneVerdict(vector.typed, vector.country)).toEqual({
        verdict: vector.verdict,
        e164: vector.e164,
      });
    },
  );

  it('holds every verdict of Tunisia and France, and the two that need no plan', () => {
    const held = new Set(vectors.map((vector) => `${vector.plan ?? '-'} ${vector.verdict}`));
    for (const plan of ['TN', 'FR']) {
      for (const verdict of ['valid', 'to_check', 'wrong_length']) {
        expect(held, `${plan} ${verdict}`).toContain(`${plan} ${verdict}`);
      }
    }
    expect(held).toContain('- needs_country');
    expect(held).toContain('- not_a_number');
  });
});
