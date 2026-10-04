// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import {
  addPlace,
  placement,
  quantityOf,
  removePlace,
  restToDefault,
  setPlaceLocation,
  setPlaceQuantity,
  toReceiptParts,
  unitsOf,
} from './split-receipt';

describe('unitsOf and quantityOf', () => {
  it('reads a decimal string into thousandths and writes it back without a float in between', () => {
    expect(unitsOf('0.3')).toBe(300n);
    expect(unitsOf('12')).toBe(12000n);
    expect(unitsOf('1.250')).toBe(1250n);
    expect(quantityOf(300n)).toBe('0.3');
    expect(quantityOf(12000n)).toBe('12');
    expect(quantityOf(1250n)).toBe('1.25');
  });

  it('refuses what is not a quantity: a sign, a comma, a letter, a fourth decimal', () => {
    for (const text of ['', '-1', '1,5', 'abc', '1.2345', '1.', '.5', ' 1']) {
      expect(unitsOf(text), text).toBeNull();
    }
  });
});

describe('placement', () => {
  it('says what is left to place: 100 received, 60 + 20 + 8 placed leaves 12', () => {
    expect(placement('100', ['60', '20', '8'])).toEqual({
      state: 'short',
      left: '12',
      placed: '88',
    });
  });

  it('is done at zero, and 0.1 + 0.2 placed of 0.3 is done, which a float sum would call short', () => {
    expect(placement('0.3', ['0.1', '0.2'])).toEqual({ state: 'done', left: '0', placed: '0.3' });
    expect(0.1 + 0.2).not.toBe(0.3);
  });

  it('is over when more is placed than was received, and says by how much', () => {
    expect(placement('10', ['6', '5'])).toEqual({ state: 'over', left: '1', placed: '11' });
  });

  it('counts an empty part as nothing and refuses an unreadable one', () => {
    expect(placement('10', ['10', ''])).toEqual({ state: 'done', left: '0', placed: '10' });
    expect(placement('10', ['6', 'x'])).toEqual({ state: 'invalid', left: '0', placed: '6' });
    expect(placement('', ['6'])).toEqual({ state: 'invalid', left: '0', placed: '6' });
  });

  it('needs something received: no quantity, or nothing placed anywhere, is never done', () => {
    expect(placement('0', []).state).toBe('invalid');
    expect(placement('10', []).state).toBe('short');
  });
});

describe('the places of a receipt', () => {
  const here = [{ id: 'l1' }, { id: 'l2' }, { id: 'l3' }];

  it('adds the first place not named yet at the end, and nothing once every place is named', () => {
    expect(addPlace([{ locationId: 'l1', quantity: '60' }], here)).toEqual([
      { locationId: 'l1', quantity: '60' },
      { locationId: 'l2', quantity: '' },
    ]);
    const all = here.map((place) => ({ locationId: place.id, quantity: '1' }));
    expect(addPlace(all, here)).toBe(all);
  });

  it('removes a place but never the last one: a receipt goes somewhere', () => {
    const two = [
      { locationId: 'l1', quantity: '60' },
      { locationId: 'l2', quantity: '40' },
    ];
    expect(removePlace(two, 0)).toEqual([{ locationId: 'l2', quantity: '40' }]);
    const one = [{ locationId: 'l1', quantity: '60' }];
    expect(removePlace(one, 0)).toBe(one);
  });

  it('names a place once: pointing a row at a place another row has changes nothing', () => {
    const two = [
      { locationId: 'l1', quantity: '60' },
      { locationId: 'l2', quantity: '40' },
    ];
    expect(setPlaceLocation(two, 1, 'l1')).toBe(two);
    expect(setPlaceLocation(two, 1, 'l3')[1]).toEqual({ locationId: 'l3', quantity: '40' });
    expect(setPlaceQuantity(two, 0, '55')[0]).toEqual({ locationId: 'l1', quantity: '55' });
  });

  it('sends what is left to the default place, adding its row when it has none', () => {
    const short = [{ locationId: 'l1', quantity: '60' }];
    expect(restToDefault(short, '100', 'l3')).toEqual([
      { locationId: 'l1', quantity: '60' },
      { locationId: 'l3', quantity: '40' },
    ]);
    const onDefault = [
      { locationId: 'l1', quantity: '60' },
      { locationId: 'l3', quantity: '5' },
    ];
    expect(restToDefault(onDefault, '100', 'l3')[1]).toEqual({ locationId: 'l3', quantity: '40' });
    const done = [{ locationId: 'l1', quantity: '100' }];
    expect(restToDefault(done, '100', 'l3')).toBe(done);
    const over = [{ locationId: 'l1', quantity: '120' }];
    expect(restToDefault(over, '100', 'l3')).toBe(over);
  });

  it('sends only the places that were given a quantity, written as the API reads it', () => {
    expect(
      toReceiptParts([
        { locationId: 'l1', quantity: '60.50' },
        { locationId: 'l2', quantity: '' },
        { locationId: 'l3', quantity: '0' },
      ]),
    ).toEqual([{ locationId: 'l1', quantity: '60.5' }]);
  });
});
