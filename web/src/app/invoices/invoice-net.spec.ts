// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import { netToPay } from './invoice-net';

describe('netToPay', () => {
  it('takes what is withheld off the total', () => {
    expect(netToPay('14917.650', [{ amount: '149.167' }])).toBe('14768.4830');
  });

  it('takes several withholdings off, each exactly', () => {
    expect(netToPay('1000.000', [{ amount: '0.100' }, { amount: '0.200' }])).toBe('999.7000');
  });

  it('is the total when nothing is withheld', () => {
    expect(netToPay('2143.000', [])).toBe('2143.0000');
  });
});
