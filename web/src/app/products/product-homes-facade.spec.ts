// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { InventoryApi } from '../inventory/inventory-api';
import { ProductHomes } from './product-homes-facade';
import { ProductsApi, ProductsRefused } from './products-api';
import { ProductsFacade } from './products-facade';
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
  const api = { homes: vi.fn(), replaceHomes: vi.fn() };
  const stock = { locations: vi.fn() };
  const products = { versionMoved: vi.fn() };
  let facade: ProductHomes;

  beforeEach(() => {
    api.homes.mockReset().mockResolvedValue([home('l1')]);
    api.replaceHomes.mockReset().mockResolvedValue(8);
    products.versionMoved.mockReset();
    stock.locations
      .mockReset()
      .mockResolvedValue([place('l1', 'e1'), place('l2', 'e1'), place('l3', 'e2')]);
    TestBed.configureTestingModule({
      providers: [
        ProductHomes,
        { provide: ProductsApi, useValue: api },
        { provide: InventoryApi, useValue: stock },
        { provide: ProductsFacade, useValue: products },
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

    expect(await facade.add('c1', 'p1', 'l2', 7)).toBe('saved');
    expect(api.replaceHomes).toHaveBeenCalledWith('c1', 'p1', 'e1', ['l1', 'l2'], 7);
    expect(facade.homes().map((row) => row.locationId)).toEqual(['l1', 'l2', 'l3']);
    expect([...facade.establishmentsAtHome()]).toEqual(['e1', 'e2']);
  });

  it('makes the first home of an establishment the main one', async () => {
    await facade.load('c1', 'p1');

    expect(await facade.add('c1', 'p1', 'l3', 7)).toBe('saved');
    expect(api.replaceHomes).toHaveBeenCalledWith('c1', 'p1', 'e2', ['l3'], 7);
  });

  it('refuses a place the company does not have, without asking the API', async () => {
    await facade.load('c1', 'p1');

    expect(await facade.add('c1', 'p1', 'nowhere', 7)).toBe('refused');
    expect(facade.error()).toBe('invalid');
    expect(api.replaceHomes).not.toHaveBeenCalled();
  });

  it('moves a home one step along its establishment’s order', async () => {
    api.homes.mockResolvedValue([home('l1'), home('l2', 'e1', 1)]);
    await facade.load('c1', 'p1');

    expect(await facade.move('c1', 'p1', home('l2', 'e1', 1), -1, 7)).toBe('saved');
    expect(api.replaceHomes).toHaveBeenCalledWith('c1', 'p1', 'e1', ['l2', 'l1'], 7);
  });

  it('removes a home, and clears the establishment when it was the last', async () => {
    api.homes.mockResolvedValue([home('l1'), home('l2', 'e1', 1), home('l3', 'e2')]);
    await facade.load('c1', 'p1');

    expect(await facade.remove('c1', 'p1', home('l1'), 7)).toBe('saved');
    expect(api.replaceHomes).toHaveBeenCalledWith('c1', 'p1', 'e1', ['l2'], 7);

    // The last one goes as an empty list, on the version read like any other list.
    expect(await facade.remove('c1', 'p1', home('l3', 'e2'), 8)).toBe('saved');
    expect(api.replaceHomes).toHaveBeenLastCalledWith('c1', 'p1', 'e2', [], 8);
  });

  it('puts a home taken off back where it stood, so the main one is the main one again', async () => {
    api.homes.mockResolvedValue([home('l2')]);
    await facade.load('c1', 'p1');

    expect(await facade.putBack('c1', 'p1', home('l1'), 8)).toBe('saved');
    expect(api.replaceHomes).toHaveBeenCalledWith('c1', 'p1', 'e1', ['l1', 'l2'], 8);
  });

  /**
   * « Monter » then « Retirer » in a breath: the second write must send the version the first left, so it is the
   * product's before the first says it is done, not once the product page has read the product again.
   */
  it('hands the version a write left to the product before reading the homes again', async () => {
    api.homes.mockResolvedValue([home('l1'), home('l2', 'e1', 1)]);
    await facade.load('c1', 'p1');

    expect(await facade.move('c1', 'p1', home('l2', 'e1', 1), -1, 7)).toBe('saved');

    expect(products.versionMoved).toHaveBeenCalledWith('p1', 8);
    const moved = products.versionMoved.mock.invocationCallOrder[0];
    const readAgain = api.homes.mock.invocationCallOrder.at(-1);
    expect(moved).toBeLessThan(readAgain!);
  });

  it('hands over no version when the write is refused', async () => {
    api.replaceHomes.mockRejectedValue(new ProductsRefused('record_changed'));
    await facade.load('c1', 'p1');

    expect(await facade.remove('c1', 'p1', home('l1'), 7)).toBe('changed');

    expect(products.versionMoved).not.toHaveBeenCalled();
  });

  /** A tab that could not save says so about itself; the product screen around it is not put in an error. */
  it('keeps the refusal, says no, and leaves what was read in place', async () => {
    await facade.load('c1', 'p1');
    api.replaceHomes.mockRejectedValue(new ProductsRefused('invalid'));

    expect(await facade.add('c1', 'p1', 'l2', 7)).toBe('refused');
    expect(facade.error()).toBe('invalid');
    expect(facade.homes()).toEqual([home('l1')]);
    expect(facade.busy()).toBe(false);
  });

  /** The product page says it and reads the product again; the homes shown become the ones saved since. */
  it('reads the homes again, saying nothing itself, when the product was saved since', async () => {
    await facade.load('c1', 'p1');
    api.replaceHomes.mockRejectedValue(new ProductsRefused('record_changed'));
    api.homes.mockResolvedValue([home('l3', 'e2')]);

    expect(await facade.add('c1', 'p1', 'l2', 7)).toBe('changed');
    expect(facade.error()).toBeNull();
    expect(facade.homes()).toEqual([home('l3', 'e2')]);
  });

  it('calls anything else a network failure rather than passing it on', async () => {
    api.homes.mockRejectedValue(new Error('boom'));

    await facade.load('c1', 'p1');

    expect(facade.error()).toBe('network');
  });
});
