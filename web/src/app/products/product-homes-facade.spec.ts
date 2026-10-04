// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { InventoryApi } from '../inventory/inventory-api';
import { ProductHomes } from './product-homes-facade';
import { ProductsApi, ProductsRefused } from './products-api';
import type { StockLocationRow } from '../inventory/inventory-types';
import type { ProductHomeRow } from './products-types';

const home = (locationId: string, establishmentId = 'e1', position = 0): ProductHomeRow => ({
  id: `h-${locationId}`,
  establishmentId,
  establishmentCode: 'SIEGE',
  establishmentName: 'Siège',
  locationId,
  locationCode: 'A-12',
  locationName: 'Zone A-12',
  position,
  main: position === 0,
});

const place = (id: string, establishmentId: string): StockLocationRow =>
  ({ id, establishmentId, parentId: null, code: id, name: id }) as StockLocationRow;

describe('ProductHomes', () => {
  const api = { homes: vi.fn(), replaceHomes: vi.fn(), clearHome: vi.fn() };
  const stock = { locations: vi.fn() };
  let facade: ProductHomes;

  beforeEach(() => {
    api.homes.mockReset().mockResolvedValue([home('l1')]);
    api.replaceHomes.mockReset().mockResolvedValue(undefined);
    api.clearHome.mockReset().mockResolvedValue(undefined);
    stock.locations
      .mockReset()
      .mockResolvedValue([place('l1', 'e1'), place('l2', 'e1'), place('l3', 'e2')]);
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

  /** The order is the API's to keep, so the list is read again after each write instead of being patched here. */
  it('adds a home after the others of its establishment and reads the homes again', async () => {
    api.homes.mockResolvedValue([home('l1')]);
    await facade.load('c1', 'p1');
    api.homes.mockResolvedValue([home('l1'), home('l2', 'e1', 1), home('l3', 'e2')]);

    expect(await facade.add('c1', 'p1', 'l2')).toBe(true);
    expect(api.replaceHomes).toHaveBeenCalledWith('c1', 'p1', 'e1', ['l1', 'l2']);
    expect(facade.homes().map((row) => row.locationId)).toEqual(['l1', 'l2', 'l3']);
    expect([...facade.establishmentsAtHome()]).toEqual(['e1', 'e2']);
  });

  it('makes the first home of an establishment the main one', async () => {
    await facade.load('c1', 'p1');

    expect(await facade.add('c1', 'p1', 'l3')).toBe(true);
    expect(api.replaceHomes).toHaveBeenCalledWith('c1', 'p1', 'e2', ['l3']);
  });

  it('refuses a place the company does not have, without asking the API', async () => {
    await facade.load('c1', 'p1');

    expect(await facade.add('c1', 'p1', 'nowhere')).toBe(false);
    expect(facade.error()).toBe('invalid');
    expect(api.replaceHomes).not.toHaveBeenCalled();
  });

  it('moves a home one step along its establishment’s order', async () => {
    api.homes.mockResolvedValue([home('l1'), home('l2', 'e1', 1)]);
    await facade.load('c1', 'p1');

    expect(await facade.move('c1', 'p1', home('l2', 'e1', 1), -1)).toBe(true);
    expect(api.replaceHomes).toHaveBeenCalledWith('c1', 'p1', 'e1', ['l2', 'l1']);
  });

  it('removes a home, and clears the establishment when it was the last', async () => {
    api.homes.mockResolvedValue([home('l1'), home('l2', 'e1', 1), home('l3', 'e2')]);
    await facade.load('c1', 'p1');

    expect(await facade.remove('c1', 'p1', home('l1'))).toBe(true);
    expect(api.replaceHomes).toHaveBeenCalledWith('c1', 'p1', 'e1', ['l2']);
    expect(api.clearHome).not.toHaveBeenCalled();

    expect(await facade.remove('c1', 'p1', home('l3', 'e2'))).toBe(true);
    expect(api.clearHome).toHaveBeenCalledWith('c1', 'p1', 'e2');
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
    api.replaceHomes.mockRejectedValue(new ProductsRefused('invalid'));

    expect(await facade.add('c1', 'p1', 'l2')).toBe(false);
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
