// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import { appended, groupByEstablishment, moved, without } from './product-home-order';
import type { ProductHomeRow } from './products-types';

const home = (
  locationId: string,
  position: number,
  establishmentId = 'e1',
  establishmentName = 'Siège',
): ProductHomeRow => ({
  id: `h-${locationId}`,
  establishmentId,
  establishmentCode: establishmentId.toUpperCase(),
  establishmentName,
  locationId,
  locationCode: locationId.toUpperCase(),
  locationName: `Zone ${locationId}`,
  position,
  main: position === 0,
});

describe('groupByEstablishment', () => {
  it('groups the homes by establishment, each in its own order, the first the main one', () => {
    const groups = groupByEstablishment([
      home('l3', 1),
      home('l9', 0, 'e2', 'Dépôt'),
      home('l1', 0),
      home('l2', 2),
    ]);

    expect(
      groups.map((group) => [group.establishmentId, group.homes.map((h) => h.locationId)]),
    ).toEqual([
      ['e1', ['l1', 'l3', 'l2']],
      ['e2', ['l9']],
    ]);
    expect(groups[0].establishmentName).toBe('Siège');
  });

  it('is empty for a product that lives nowhere in particular', () => {
    expect(groupByEstablishment([])).toEqual([]);
  });
});

describe('the order of one establishment’s homes', () => {
  it('appends a place after the others, and never twice', () => {
    expect(appended(['a', 'b'], 'c')).toEqual(['a', 'b', 'c']);
    expect(appended(['a', 'b'], 'a')).toEqual(['a', 'b']);
    expect(appended([], 'a')).toEqual(['a']);
  });

  it('drops a place and leaves the order of the rest', () => {
    expect(without(['a', 'b', 'c'], 'b')).toEqual(['a', 'c']);
    expect(without(['a'], 'a')).toEqual([]);
    expect(without(['a', 'b'], 'z')).toEqual(['a', 'b']);
  });

  it('moves a place one step towards the main end or away from it, and stops at the ends', () => {
    expect(moved(['a', 'b', 'c'], 'b', -1)).toEqual(['b', 'a', 'c']);
    expect(moved(['a', 'b', 'c'], 'b', 1)).toEqual(['a', 'c', 'b']);
    expect(moved(['a', 'b', 'c'], 'a', -1)).toEqual(['a', 'b', 'c']);
    expect(moved(['a', 'b', 'c'], 'c', 1)).toEqual(['a', 'b', 'c']);
    expect(moved(['a', 'b'], 'z', -1)).toEqual(['a', 'b']);
  });
});
