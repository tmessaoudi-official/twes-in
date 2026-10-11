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
  planShapes: [],
  structureShapes: [],
};
const SEARCH = {
  page: 1,
  itemsPerPage: 25,
  q: '',
  locationIds: [],
  establishmentIds: [],
  productIds: [],
  negative: null,
  expired: null,
  intervals: {},
  order: null,
} as const;
const MOVEMENTS_SEARCH = {
  page: 1,
  itemsPerPage: 25,
  q: '',
  productIds: ['p1'],
  locationIds: [],
  kinds: [],
  sourceTypes: [],
  reasons: [],
  costToComplete: null,
  intervals: {},
  lot: null,
  order: null,
} as const;
const level: StockLevelRow = {
  id: 'p1:l1',
  productId: 'p1',
  productReference: 'ART-1',
  productName: 'Portable',
  unitCode: 'C62',
  unitName: 'Unité',
  unitDecimals: 0,
  locationId: 'l1',
  locationCode: '000',
  locationName: 'Siège',
  establishmentId: 'e1',
  quantity: '10.000',
  lotId: null,
  lotCode: null,
  lotExpiresOn: null,
  lotReleased: false,
  mainPhotoId: null,
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
    receiveSplit: vi.fn(),
    repeatDrawing: vi.fn(),
    drawings: vi.fn(),
    holdings: vi.fn(),
    floors: vi.fn(),
    locationHomes: vi.fn(),
    whereabouts: vi.fn(),
    pickProducts: vi.fn(),
    lossFiles: vi.fn(),
    attachToLoss: vi.fn(),
    restoreToLoss: vi.fn(),
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
    api.receiveSplit.mockReset().mockResolvedValue(['m1', 'm2']);
    api.repeatDrawing.mockReset().mockResolvedValue([]);
    api.drawings.mockReset().mockResolvedValue([]);
    api.holdings.mockReset().mockResolvedValue(new Map());
    api.floors.mockReset().mockResolvedValue([]);
    api.locationHomes.mockReset().mockResolvedValue([]);
    api.whereabouts.mockReset();
    api.pickProducts.mockReset().mockResolvedValue([]);
    TestBed.configureTestingModule({ providers: [{ provide: InventoryApi, useValue: api }] });
    facade = TestBed.inject(InventoryFacade);
  });

  /** A product found nowhere answers no row to carry its words, so they come from the product itself. */
  it('finds goods on the map in the order asked, naming from the product those found nowhere', async () => {
    const vis = {
      productId: 'p1',
      productReference: 'VIS-6X40',
      productName: 'Vis 6x40',
      unitName: 'Pièce',
      unitDecimals: 0,
      rows: [],
    };
    api.whereabouts.mockResolvedValue([vis]);
    api.pickProducts.mockResolvedValue([
      {
        id: 'p2',
        reference: 'RON-M6',
        name: 'Rondelle M6',
        unitCode: 'C62',
        unitDecimals: 0,
        homeLocationId: null,
        tracking: 'none',
      },
    ]);

    await facade.loadWhereabouts('c1', ['p2', 'p1']);

    expect(api.whereabouts).toHaveBeenCalledWith('c1', ['p2', 'p1']);
    expect(api.pickProducts).toHaveBeenCalledWith('c1', { ids: ['p2'] });
    expect(facade.whereabouts()).toEqual({
      productIds: ['p2', 'p1'],
      products: [
        {
          productId: 'p2',
          productReference: 'RON-M6',
          productName: 'Rondelle M6',
          unitName: '',
          unitDecimals: 0,
          rows: [],
        },
        vis,
      ],
    });

    await facade.loadWhereabouts('c1', []);
    expect(facade.whereabouts()).toBeNull();
  });

  /** The picker resolves twenty ids at a time, so a long note's missing lines are asked for in several questions. */
  it('asks the words of many products found nowhere twenty at a time', async () => {
    api.whereabouts.mockResolvedValue([]);
    const ids = Array.from({ length: 45 }, (_, at) => `p${at}`);

    await facade.loadWhereabouts('c1', ids);

    expect(api.pickProducts.mock.calls.map(([, asked]) => asked.ids.length)).toEqual([20, 20, 5]);
    expect(facade.whereabouts()?.products).toHaveLength(45);
  });

  /**
   * Going from rack to rack sends one read per press, and they need not come back in order: the panel shows the rack
   * chosen last, never the first one's late answer.
   */
  it('reads what a place holds, keeping only the answer for the place chosen last', async () => {
    let answerFirst: (page: { rows: unknown[]; total: number }) => void = () => undefined;
    api.levels
      .mockReturnValueOnce(new Promise((resolve) => (answerFirst = resolve)))
      .mockResolvedValueOnce({ rows: [level], total: 1 });

    const first = facade.loadContents('c1', 'r1');
    await facade.loadContents('c1', 'r2');
    answerFirst({ rows: [], total: 0 });
    await first;

    expect(api.levels).toHaveBeenLastCalledWith(
      'c1',
      expect.objectContaining({ locationIds: ['r2'], itemsPerPage: 100 }),
    );
    expect(api.locationHomes).toHaveBeenCalledWith('c1', 'r2');
    expect(facade.contents()).toEqual({
      locationId: 'r2',
      q: '',
      levels: [level],
      total: 1,
      homes: [],
    });

    await facade.loadContents('c1', null);
    expect(facade.contents()).toBeNull();
  });

  /** A place searched is read again with its words: a movement elsewhere must not widen what the person narrowed. */
  it('searches a place with words, and reads it again with the same words', async () => {
    api.levels.mockResolvedValue({ rows: [level], total: 1 });

    await facade.loadContents('c1', 'r1', 'vis');
    expect(api.levels).toHaveBeenLastCalledWith(
      'c1',
      expect.objectContaining({ locationIds: ['r1'], q: 'vis' }),
    );
    expect(facade.contents()?.q).toBe('vis');

    api.levels.mockClear();
    await facade.reloadContents('c1');
    expect(api.levels).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ locationIds: ['r1'], q: 'vis' }),
    );
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

  it("reads one page of a product's movements, and says how many there are in all", async () => {
    api.movements.mockResolvedValue({ rows: [], total: 7 });

    await facade.loadMovements('c1', MOVEMENTS_SEARCH);

    expect(api.movements).toHaveBeenCalledWith('c1', MOVEMENTS_SEARCH);
    expect(facade.movementsTotal()).toBe(7);
    expect(api.levels).not.toHaveBeenCalled();
  });

  it('reads the movements page in hand again, and does nothing before the list has asked for one', async () => {
    api.movements.mockResolvedValue({ rows: [], total: 0 });

    await facade.reloadMovements('c1');
    expect(api.movements).not.toHaveBeenCalled();

    await facade.loadMovements('c1', MOVEMENTS_SEARCH);
    await facade.reloadMovements('c1');
    expect(api.movements).toHaveBeenCalledTimes(2);
    expect(api.movements).toHaveBeenLastCalledWith('c1', MOVEMENTS_SEARCH);
  });

  // Row 74 (b): the loss's row says how many files it keeps, so the page in hand is read again once one is kept.
  it("keeps a file with a loss, then reads the movements again; a loss's files it cannot read say why", async () => {
    await facade.loadMovements('c1', MOVEMENTS_SEARCH);
    const file = new File(['%PDF-1.4'], 'plainte.pdf', { type: 'application/pdf' });
    api.attachToLoss.mockResolvedValue({ id: 'f1' });

    expect(await facade.attachToLoss('c1', 'm3', file)).toBe(true);
    expect(api.attachToLoss).toHaveBeenCalledWith('c1', 'm3', file);
    expect(api.movements).toHaveBeenCalledTimes(2);

    api.lossFiles.mockRejectedValue(new InventoryRefused('not_found'));
    expect(await facade.lossFiles('c1', 'm1')).toBeNull();
    expect(facade.error()).toBe('not_found');
  });

  it('puts a file back on its loss and reads the movements again; a refusal is returned, not written', async () => {
    await facade.loadMovements('c1', MOVEMENTS_SEARCH);
    api.restoreToLoss.mockResolvedValue(undefined);

    expect(await facade.restoreToLoss('c1', 'm3', 'f1')).toBeNull();
    expect(api.restoreToLoss).toHaveBeenCalledWith('c1', 'm3', 'f1');
    expect(api.movements).toHaveBeenCalledTimes(2);

    api.restoreToLoss.mockRejectedValue(new InventoryRefused('files_full'));
    expect(await facade.restoreToLoss('c1', 'm3', 'f1')).toBe('files_full');
    expect(facade.error()).toBeNull();
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

  it('records a receipt shared over several places in one call, then reads the page again', async () => {
    api.levels
      .mockResolvedValueOnce({ rows: [], total: 0 })
      .mockResolvedValueOnce({ rows: [level], total: 1 });
    await facade.loadStock('c1', SEARCH);

    const input = {
      productId: 'p1',
      parts: [
        { locationId: 'l1', quantity: '6' },
        { locationId: 'l2', quantity: '4' },
      ],
    };
    expect(await facade.receiveSplit('c1', input)).toBe(true);

    expect(api.receiveSplit).toHaveBeenCalledWith('c1', input);
    expect(api.record).not.toHaveBeenCalled();
    expect(api.levels).toHaveBeenLastCalledWith('c1', SEARCH);
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

  /**
   * A repeat creates stock LOCATIONS as well as rectangles, so the locations are read again with the floor —
   * otherwise the racks it had just made would be missing from every picker on the screen that made them,
   * including the one the next rectangle is drawn for.
   */
  it('reads the locations again after a repeat, not only the floor', async () => {
    await facade.loadStockContext('c1');
    api.locations.mockClear();

    await expect(
      facade.repeatDrawing('c1', 'f1', 'd1', {
        count: 2,
        spacing: '0.6',
        way: 'down',
        firstCode: 'R2',
      }),
    ).resolves.toBe(true);

    expect(api.repeatDrawing).toHaveBeenCalledWith('c1', 'd1', {
      count: 2,
      spacing: '0.6',
      way: 'down',
      firstCode: 'R2',
    });
    expect(api.locations).toHaveBeenCalledWith('c1');
    expect(api.drawings).toHaveBeenCalledWith('c1', 'f1');
  });

  /** What each shelf holds is read with the floor, and again after a movement without reading the floor again. */
  it('reads what each drawn place holds with its floor, and again on its own', async () => {
    api.holdings.mockResolvedValueOnce(new Map([['l1', 3]]));
    await facade.loadDrawings('c1', 'f1');

    expect(api.holdings).toHaveBeenCalledWith('c1', 'f1');
    expect(facade.holdings().get('l1')).toBe(3);

    api.drawings.mockClear();
    api.holdings.mockResolvedValueOnce(new Map([['l1', 4]]));
    await facade.reloadHoldings('c1');

    expect(api.drawings).not.toHaveBeenCalled();
    expect(facade.holdings().get('l1')).toBe(4);
  });
});
