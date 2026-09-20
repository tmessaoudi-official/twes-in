// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { InventoryApi, InventoryRefused } from './inventory-api';
import { InventoryFacade } from './inventory-facade';
import type {
  StockLevelRow,
  StockLocationInput,
  StockLocationRow,
  StockOptions,
} from './inventory-types';

const site: StockLocationRow = {
  id: 'l1',
  establishmentId: 'e1',
  parentId: null,
  kind: 'site',
  code: '000',
  name: 'Siège',
  isDefault: true,
  childCount: 0,
  movementCount: 1,
};
const options: StockOptions = {
  establishments: [{ id: 'e1', code: '000', name: 'Siège' }],
};
const SEARCH = {
  page: 1,
  itemsPerPage: 25,
  q: '',
  locationId: null,
  establishmentId: null,
  order: null,
} as const;
const level: StockLevelRow = {
  id: 'p1:l1',
  productId: 'p1',
  productReference: 'ART-1',
  productName: 'Portable',
  unitCode: 'C62',
  unitDecimals: 0,
  locationId: 'l1',
  locationCode: '000',
  locationName: 'Siège',
  establishmentId: 'e1',
  quantity: '10.000',
};
const zone: StockLocationInput = {
  establishmentId: 'e1',
  parentId: null,
  kind: 'zone',
  code: 'Z1',
  name: 'Zone froide',
};

describe('InventoryFacade', () => {
  const api = {
    options: vi.fn(),
    levels: vi.fn(),
    locations: vi.fn(),
    movements: vi.fn(),
    createLocation: vi.fn(),
    reviseLocation: vi.fn(),
    deleteLocation: vi.fn(),
    record: vi.fn(),
  };
  let facade: InventoryFacade;

  beforeEach(() => {
    api.options.mockReset().mockResolvedValue(options);
    api.levels.mockReset().mockResolvedValue({ rows: [level], total: 1 });
    api.locations.mockReset().mockResolvedValue([site]);
    api.movements.mockReset().mockResolvedValue([]);
    api.createLocation.mockReset().mockResolvedValue(site);
    api.reviseLocation.mockReset().mockResolvedValue(site);
    api.deleteLocation.mockReset().mockResolvedValue(undefined);
    api.record.mockReset().mockResolvedValue({});
    TestBed.configureTestingModule({ providers: [{ provide: InventoryApi, useValue: api }] });
    facade = TestBed.inject(InventoryFacade);
  });

  it('reads one page of stock with what it names: the options and the locations', async () => {
    await facade.loadStockContext('c1');
    await facade.loadStock('c1', SEARCH);

    expect(api.levels).toHaveBeenCalledWith('c1', SEARCH);
    expect([facade.levels(), facade.total(), facade.locations(), facade.options()]).toEqual([
      [level],
      1,
      [site],
      options,
    ]);
    expect(facade.error()).toBeNull();
  });

  it("reads one product's movements, which name what they moved themselves", async () => {
    await facade.loadMovements('c1', 'p1');

    expect(api.movements).toHaveBeenCalledWith('c1', 'p1');
    expect(api.levels).not.toHaveBeenCalled();
  });

  it('records a receipt, then reads the page it was recorded on again', async () => {
    api.levels
      .mockResolvedValueOnce({ rows: [], total: 0 })
      .mockResolvedValueOnce({ rows: [level], total: 1 });
    await facade.loadStock('c1', SEARCH);

    const input = {
      operation: 'receive' as const,
      productId: 'p1',
      locationId: 'l1',
      quantity: '10',
    };
    expect(await facade.record('c1', input)).toBe(true);

    expect(api.record).toHaveBeenCalledWith('c1', input);
    expect(api.levels).toHaveBeenLastCalledWith('c1', SEARCH);
    expect(facade.levels()).toEqual([level]);
  });

  it('says why a write was refused, and leaves what was read', async () => {
    await facade.loadLocations('c1');
    api.createLocation.mockRejectedValue(new InventoryRefused('code_taken'));
    api.deleteLocation.mockRejectedValue(new Error('offline'));

    expect(await facade.createLocation('c1', zone)).toBe(false);
    expect(facade.error()).toBe('code_taken');
    expect(await facade.deleteLocation('c1', 'l1')).toBe(false);
    expect(facade.error()).toBe('network');
    expect(facade.locations()).toEqual([site]);

    facade.clearError();
    expect(facade.error()).toBeNull();
  });

  it('reads the locations again after one is added, revised or deleted', async () => {
    expect(await facade.createLocation('c1', zone)).toBe(true);
    expect(await facade.reviseLocation('c1', 'l2', zone)).toBe(true);
    expect(await facade.deleteLocation('c1', 'l2')).toBe(true);

    expect(api.reviseLocation).toHaveBeenCalledWith('c1', 'l2', zone);
    expect(api.deleteLocation).toHaveBeenCalledWith('c1', 'l2');
    expect(api.locations).toHaveBeenCalledTimes(3);
  });
});
