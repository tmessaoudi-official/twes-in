// SPDX-License-Identifier: AGPL-3.0-or-later

import { versus } from './versus';

describe('versus', () => {
  it('compares an amount with last month’s in exact decimals: up, down or the same, the size always positive', () => {
    expect(versus('1881.000', '1850.050')).toEqual({ kind: 'up', amount: '30.950' });
    expect(versus('800.000', '1200.000')).toEqual({ kind: 'down', amount: '400.000' });
    expect(versus('10.00', '10.000')).toEqual({ kind: 'same', amount: '0.000' });
    expect(versus('-20.000', '30.500')).toEqual({ kind: 'down', amount: '50.500' });
    expect(versus('0.1', '0.3')).toEqual({ kind: 'down', amount: '0.2' });
  });
});
