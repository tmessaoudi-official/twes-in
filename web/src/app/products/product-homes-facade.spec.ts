// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { InventoryApi } from '../inventory/inventory-api';
import { ProductHomes } from './product-homes-facade';
import { ProductsApi, ProductsRefused } from './products-api';
import type { ProductHomeRow } from './products-types';

const home = (locationId: string, establishmentId = 'e1'): ProductHomeRow => ({
  id: `h-${establishmentId}`,
  establishmentId,
  establishmentCode: 'SIEGE',
  establishmentName: 'Siège',
  locationId,
  locationCode: 'A-12',
  locationName: 'Zone A-12',
});

describe('ProductHomes', () => {
  const api = { homes: vi.fn(), setHome: vi.fn(), clearHome: vi.fn() };
  const stock = { locations: vi.fn() };
  let facade: ProductHomes;

  beforeEach(() => {
    api.homes.mockReset().mockResolvedValue([home('l1')]);
    api.setHome.mockReset().mockResolvedValue(home('l1'));
    api.clearHome.mockReset().mockResolvedValue(undefined);
    stock.locations.mockReset().mockResolvedValue([]);
    TestBed.configureTestingModule({
      providers: [
        ProductHomes,
        { provide: ProductsApi, useValue: api },
        { provide: InventoryApi, useValue: stock },
      ],
    });
    facade = TestBed.inject(ProductHomes);
  });

  it('reads the homes and the locations they may be chosen from together', async () => {
    await facade.load('c1', 'p1');

    expect(api.homes).toHaveBeenCalledWith('c1', 'p1');
    expect(stock.locations).toHaveBeenCalledWith('c1');
    expect(facade.homes()).toEqual([home('l1')]);
  });

  /** The API moves a home rather than doubling it, so the list is read again instead of being patched here. */
  it('reads the homes again after one is set, and says which establishments have one', async () => {
    await facade.load('c1', 'p1');
    api.homes.mockResolvedValue([home('l2'), home('l3', 'e2')]);

    expect(await facade.set('c1', 'p1', 'l2')).toBe(true);
    expect(api.setHome).toHaveBeenCalledWith('c1', 'p1', 'l2');
    expect(facade.homes().map((row) => row.locationId)).toEqual(['l2', 'l3']);
    expect([...facade.establishmentsAtHome()]).toEqual(['e1', 'e2']);
  });

  it('reads the homes again after one is cleared', async () => {
    await facade.load('c1', 'p1');
    api.homes.mockResolvedValue([]);

    expect(await facade.clear('c1', 'p1', 'e1')).toBe(true);
    expect(api.clearHome).toHaveBeenCalledWith('c1', 'p1', 'e1');
    expect(facade.homes()).toEqual([]);
  });

  /** A tab that could not save says so about itself; the product screen around it is not put in an error. */
  it('keeps the refusal, says no, and leaves what was read in place', async () => {
    await facade.load('c1', 'p1');
    api.setHome.mockRejectedValue(new ProductsRefused('invalid'));

    expect(await facade.set('c1', 'p1', 'l9')).toBe(false);
    expect(facade.error()).toBe('invalid');
    expect(facade.homes()).toEqual([home('l1')]);
    expect(facade.busy()).toBe(false);
  });

  it('calls anything else a network failure rather than passing it on', async () => {
    api.homes.mockRejectedValue(new Error('boom'));

    await facade.load('c1', 'p1');

    expect(facade.error()).toBe('network');
  });
});
