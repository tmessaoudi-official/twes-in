// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { InventoryApi } from './inventory-api';
import type { StockLocationInput, StockLocationRow, StockMovementRow } from './inventory-types';

const zone: StockLocationRow = {
  id: 'l2',
  establishmentId: 'e1',
  parentId: 'l1',
  kind: 'zone',
  code: 'Z1',
  name: 'Zone froide',
  isDefault: false,
  childCount: 1,
  movementCount: 4,
};

/** A search that asks for nothing but the first page, so a case names only what it changes. */
const SEARCH = {
  page: 1,
  itemsPerPage: 25,
  q: '',
  locationId: null,
  establishmentId: null,
  order: null,
} as const;

const received: StockMovementRow = {
  id: 'm1',
  productId: 'p1',
  productReference: 'ART-1',
  productName: 'Portable',
  unitCode: 'C62',
  unitDecimals: 0,
  locationId: 'l1',
  locationCode: '000',
  locationName: 'Siège',
  kind: 'in',
  quantity: '10.000',
  sourceType: 'receipt',
  sourceId: null,
  recordedBy: 'u1',
  at: '2026-09-15T09:00:00+00:00',
};

describe('InventoryApi', () => {
  let api: InventoryApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(InventoryApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads the options, the stock and the locations', async () => {
    const options = api.options('c 1');
    http.expectOne('/api/companies/c%201/stock-options').flush({
      establishments: [{ id: 'e1', code: '000', name: 'Siège' }],
      planShapes: [{ shape: 'rack', width: '2.400', depth: '0.600' }],
      structureShapes: [{ kind: 'wall', width: '5.000', depth: '0.150', height: '2.800' }],
    });
    expect(await options).toEqual({
      establishments: [{ id: 'e1', code: '000', name: 'Siège' }],
      // The palette's shapes come from the API at THIS company's sizes, as metres the screen can work in.
      planShapes: [{ shape: 'rack', width: 2.4, depth: 0.6 }],
      // The structure tools the same way, and with a height, which no palette shape carries.
      structureShapes: [{ kind: 'wall', width: 5, depth: 0.15, height: 2.8 }],
    });

    // An older API that does not send them yet leaves both palettes empty rather than undefined.
    const bare = api.options('c2');
    http.expectOne('/api/companies/c2/stock-options').flush({ establishments: [] });
    expect([(await bare).planShapes, (await bare).structureShapes]).toEqual([[], []]);

    const levels = api.levels('c1', SEARCH);
    const level = {
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
      quantity: '-2.000',
    };
    http
      .expectOne((candidate) => candidate.url === '/api/companies/c1/stock-levels')
      .flush({ member: [level], totalItems: 7 });
    expect(await levels).toEqual({ rows: [level], total: 7 });

    const locations = api.locations('c1');
    http.expectOne('/api/companies/c1/stock-locations').flush([{ ...zone, kind: 'cellar' }]);
    expect(await locations).toEqual([{ ...zone, kind: 'zone' }]);
  });

  it('asks the picker for the few stocked products a person means, and by id for a named one', async () => {
    const searched = api.pickProducts('c1', { words: '  port ' });
    const search = http.expectOne(
      (request) => request.url === '/api/companies/c1/stock-options/products',
    );
    expect(search.request.params.get('q')).toBe('port');
    search.flush([
      { id: 'p1', reference: 'ART-1', name: 'Portable', unitCode: 'C62', unitDecimals: 0 },
      {
        id: 'p2',
        reference: 'ART-2',
        name: 'Écran',
        unitCode: 'C62',
        unitDecimals: 0,
        homeLocationId: 'l2',
      },
    ]);
    // A product at home nowhere in particular — or in two places at once — comes without the field at all, and
    // reads here as null rather than undefined, so the form asks "is there a home" of one value only.
    expect(await searched).toEqual([
      {
        id: 'p1',
        reference: 'ART-1',
        name: 'Portable',
        unitCode: 'C62',
        unitDecimals: 0,
        homeLocationId: null,
      },
      {
        id: 'p2',
        reference: 'ART-2',
        name: 'Écran',
        unitCode: 'C62',
        unitDecimals: 0,
        homeLocationId: 'l2',
      },
    ]);

    const named = api.pickProducts('c1', { ids: ['p1', 'p2'] });
    const resolve = http.expectOne(
      (request) => request.url === '/api/companies/c1/stock-options/products',
    );
    // Asking for what a movement names is not a search: the words are left out entirely.
    expect(resolve.request.params.getAll('ids[]')).toEqual(['p1', 'p2']);
    expect(resolve.request.params.has('q')).toBe(false);
    resolve.flush([]);
    expect(await named).toEqual([]);
  });

  it('asks for one page of movements, sending only what the search narrows to', async () => {
    const narrowed = api.movements('c1', {
      page: 2,
      itemsPerPage: 25,
      q: ' portable ',
      productId: 'p 1',
      locationId: null,
      kind: 'in',
      sourceType: null,
      order: { key: 'quantity', direction: 'asc' },
    });
    const asked = http.expectOne((request) => request.url === '/api/companies/c1/stock-movements');
    expect(asked.request.headers.get('Accept')).toBe('application/ld+json');
    expect([...asked.request.params.keys()].sort()).toEqual([
      'itemsPerPage',
      'kind',
      'order[quantity]',
      'page',
      'productId',
      'q',
    ]);
    // The words are sent trimmed, and what the search left out is left out rather than sent empty.
    expect(asked.request.params.get('q')).toBe('portable');
    asked.flush({ member: [received], totalItems: 40 });
    expect(await narrowed).toEqual({ rows: [received], total: 40 });

    const all = api.movements('c1', {
      page: 1,
      itemsPerPage: 25,
      q: '',
      productId: null,
      locationId: null,
      kind: null,
      sourceType: null,
      order: null,
    });
    const plain = http.expectOne((request) => request.url === '/api/companies/c1/stock-movements');
    expect([...plain.request.params.keys()].sort()).toEqual(['itemsPerPage', 'page']);
    plain.flush({ member: [], totalItems: 0 });
    expect(await all).toEqual({ rows: [], total: 0 });
  });

  it('creates, revises and deletes a location, and says a code is taken or a location in use', async () => {
    const input: StockLocationInput = {
      establishmentId: 'e1',
      parentId: null,
      kind: 'zone',
      code: 'Z1',
      name: 'Zone froide',
    };

    const created = api.createLocation('c1', input);
    const post = http.expectOne('/api/companies/c1/stock-locations');
    expect([post.request.method, post.request.body]).toEqual(['POST', input]);
    post.flush(zone);
    expect(await created).toEqual(zone);

    const revised = api.reviseLocation('c1', 'l2', input);
    const put = http.expectOne('/api/companies/c1/stock-locations/l2');
    expect(put.request.method).toBe('PUT');
    put.flush({ detail: 'taken' }, { status: 409, statusText: 'Conflict' });
    await expect(revised).rejects.toMatchObject({ code: 'code_taken' });

    const deleted = api.deleteLocation('c1', 'l2');
    const del = http.expectOne('/api/companies/c1/stock-locations/l2');
    expect(del.request.method).toBe('DELETE');
    del.flush({ detail: 'in use' }, { status: 409, statusText: 'Conflict' });
    await expect(deleted).rejects.toMatchObject({ code: 'in_use' });
  });

  it('records a receipt or a count, and says why one was refused', async () => {
    const recorded = api.record('c1', {
      operation: 'receive',
      productId: 'p1',
      locationId: 'l1',
      quantity: '10',
    });
    const post = http.expectOne('/api/companies/c1/stock-movements');
    expect([post.request.method, post.request.body]).toEqual([
      'POST',
      { operation: 'receive', productId: 'p1', locationId: 'l1', quantity: '10' },
    ]);
    post.flush(received);
    expect(await recorded).toEqual(received);

    const refused = api.record('c1', {
      operation: 'count',
      productId: 'p1',
      locationId: 'l1',
      quantity: '1.5',
    });
    http
      .expectOne('/api/companies/c1/stock-movements')
      .flush({ detail: 'quantity' }, { status: 422, statusText: 'Unprocessable Entity' });
    await expect(refused).rejects.toMatchObject({ code: 'invalid' });

    const unreachable = api.levels('c1', SEARCH);
    http
      .expectOne((candidate) => candidate.url === '/api/companies/c1/stock-levels')
      .error(new ProgressEvent('error'));
    await expect(unreachable).rejects.toMatchObject({ code: 'network' });

    const gone = api.levels('c1', SEARCH);
    http
      .expectOne((candidate) => candidate.url === '/api/companies/c1/stock-levels')
      .flush({}, { status: 404, statusText: 'Not Found' });
    await expect(gone).rejects.toMatchObject({ code: 'not_found' });
  });

  it('asks the API for one page of stock, with what it searches, narrows and sorts by', async () => {
    const pending = api.levels('c1', {
      ...SEARCH,
      page: 2,
      itemsPerPage: 50,
      q: '  portable  ',
      locationId: 'l1',
      establishmentId: 'e1',
      order: { key: 'quantity', direction: 'desc' },
    });
    const request = http.expectOne(
      (candidate) =>
        candidate.url === '/api/companies/c1/stock-levels' && candidate.method === 'GET',
    );
    expect(request.request.headers.get('Accept')).toBe('application/ld+json');
    expect(request.request.params.get('page')).toBe('2');
    expect(request.request.params.get('itemsPerPage')).toBe('50');
    // Trimmed, so a trailing space is not a different search.
    expect(request.request.params.get('q')).toBe('portable');
    expect(request.request.params.get('locationId')).toBe('l1');
    expect(request.request.params.get('establishmentId')).toBe('e1');
    expect(request.request.params.get('order[quantity]')).toBe('desc');
    request.flush({ member: [], totalItems: 0 });
    expect(await pending).toEqual({ rows: [], total: 0 });
  });

  it('refuses a page that came without its total, rather than showing one page as the whole stock', async () => {
    const pending = api.levels('c1', SEARCH);
    const request = http.expectOne(
      (candidate) =>
        candidate.url === '/api/companies/c1/stock-levels' && candidate.method === 'GET',
    );
    expect(request.request.params.has('q')).toBe(false);
    expect(request.request.params.has('locationId')).toBe(false);
    request.flush({ member: [] });
    await expect(pending).rejects.toThrow();
  });

  it('reads the floors the stock is drawn on', async () => {
    const pending = api.floors('c1');
    const request = http.expectOne('/api/companies/c1/stock-floors');
    expect(request.request.method).toBe('GET');
    request.flush([
      { id: 'f1', establishmentId: 'e1', name: 'Rez-de-chaussée', level: 0, drawingCount: 3 },
    ]);

    expect(await pending).toEqual([
      {
        id: 'f1',
        establishmentId: 'e1',
        name: 'Rez-de-chaussée',
        level: 0,
        widthMetres: null,
        depthMetres: null,
        imageFileId: null,
        imageMetresWide: null,
        imageOpacity: 35,
        drawingCount: 3,
      },
    ]);
  });

  /**
   * The building, which the page reads through here and nowhere else. The name is the field this case exists for:
   * every screen spec sets its rows directly, so a mapper that quietly dropped it would be invisible to all of them
   * and would show every piece of the building unnamed (docs/SPEC.md § 7, 2026-09-22).
   */
  it('reads a piece of the building with the name the store gave it, and builds one with it', async () => {
    const pending = api.structures('c1', 'f1');
    const request = http.expectOne('/api/companies/c1/stock-floors/f1/structures');
    expect(request.request.method).toBe('GET');
    request.flush([
      {
        id: 's1',
        floorId: 'f1',
        kind: 'dock',
        name: 'Porte du quai 2',
        x: '18.000',
        y: '0.000',
        width: '3.000',
        depth: '0.200',
        rotation: 0,
        height: '4.000',
      },
    ]);

    expect(await pending).toEqual([
      {
        id: 's1',
        floorId: 'f1',
        kind: 'dock',
        name: 'Porte du quai 2',
        x: '18.000',
        y: '0.000',
        width: '3.000',
        depth: '0.200',
        rotation: 0,
        height: '4.000',
      },
    ]);

    // And it travels the other way: what the form typed is what the API is asked to write down.
    const built = api.buildStructure('c1', 'f1', {
      kind: 'wall',
      name: 'Mur nord',
      x: '0.000',
      y: '0.000',
      width: '6.900',
      depth: '0.200',
      rotation: 0,
      height: '3.000',
    });
    const post = http.expectOne('/api/companies/c1/stock-floors/f1/structures');
    expect(post.request.method).toBe('POST');
    expect(post.request.body.name).toBe('Mur nord');
    post.flush({ id: 's2', floorId: 'f1', kind: 'wall', name: 'Mur nord' });
    expect((await built).name).toBe('Mur nord');
  });

  it('says the level is taken when another floor of the establishment already has it', async () => {
    const pending = api.createFloor('c1', {
      establishmentId: 'e1',
      name: 'Étage 1',
      level: 1,
      widthMetres: '30',
      depthMetres: '20',
      imageFileId: null,
      imageMetresWide: null,
      imageOpacity: 35,
    });
    const request = http.expectOne('/api/companies/c1/stock-floors');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      establishmentId: 'e1',
      name: 'Étage 1',
      level: 1,
      widthMetres: '30',
      depthMetres: '20',
      imageFileId: null,
      imageMetresWide: null,
      imageOpacity: 35,
    });
    request.flush('taken', { status: 409, statusText: 'Conflict' });

    await expect(pending).rejects.toMatchObject({ code: 'level_taken' });
  });

  it('reads one floor’s rectangles, in metres as the API holds them', async () => {
    const pending = api.drawings('c1', 'f1');
    const request = http.expectOne('/api/companies/c1/stock-floors/f1/drawings');
    expect(request.request.method).toBe('GET');
    request.flush([
      {
        id: 'd1',
        floorId: 'f1',
        locationId: 'l2',
        locationCode: 'R1',
        locationName: 'Rayonnage 1',
        locationKind: 'rack',
        x: '2.500',
        y: '4.000',
        width: '3.900',
        depth: '0.600',
        rotation: 90,
        height: '2.100',
      },
    ]);

    expect(await pending).toEqual([
      {
        id: 'd1',
        floorId: 'f1',
        locationId: 'l2',
        locationCode: 'R1',
        locationName: 'Rayonnage 1',
        locationKind: 'rack',
        x: '2.500',
        y: '4.000',
        width: '3.900',
        depth: '0.600',
        rotation: 90,
        height: '2.100',
      },
    ]);
  });

  /** Drawing goes to the floor; moving a rectangle already drawn goes to the rectangle, which never changes floor. */
  it('draws on a floor and moves a rectangle by its own address', async () => {
    const rect = {
      locationId: 'l2',
      x: '1.000',
      y: '1.000',
      width: '3.000',
      depth: '1.000',
      rotation: 0,
      height: '2.000',
    };

    void api.draw('c1', 'f1', rect);
    const drawn = http.expectOne('/api/companies/c1/stock-floors/f1/drawings');
    expect(drawn.request.method).toBe('POST');
    expect(drawn.request.body).toEqual(rect);
    drawn.flush({ id: 'd1', floorId: 'f1', ...rect });

    void api.moveDrawing('c1', 'd1', rect);
    const moved = http.expectOne('/api/companies/c1/stock-drawings/d1');
    expect(moved.request.method).toBe('PUT');
    moved.flush({ id: 'd1', floorId: 'f1', ...rect });
  });

  /**
   * A repeat answers what it created, so the screen never reads the floor back to learn what it had just asked for
   * — a read that would race anyone else drawing on the same plan.
   */
  it('repeats a rectangle and gives back the copies it made', async () => {
    const pending = api.repeatDrawing('c1', 'd1', {
      count: 2,
      spacing: '0.6',
      way: 'down',
      firstCode: 'R2',
    });
    const request = http.expectOne('/api/companies/c1/stock-drawings/d1/repeat');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      count: 2,
      spacing: '0.6',
      way: 'down',
      firstCode: 'R2',
    });
    request.flush({
      count: 2,
      spacing: '0.6',
      way: 'down',
      firstCode: 'R2',
      drawings: [
        { id: 'd2', floorId: 'f1', locationId: 'l2', locationCode: 'R2', locationKind: 'rack' },
        { id: 'd3', floorId: 'f1', locationId: 'l3', locationCode: 'R3', locationKind: 'rack' },
      ],
    });

    await expect(pending).resolves.toMatchObject([{ id: 'd2' }, { id: 'd3' }]);
  });

  /** A code already taken is the repeat's own refusal, and it names the code so the panel can say which. */
  it('passes a taken code through as a refusal', async () => {
    const pending = api.repeatDrawing('c1', 'd1', {
      count: 2,
      spacing: '0.6',
      way: 'down',
      firstCode: 'R2',
    });
    http
      .expectOne('/api/companies/c1/stock-drawings/d1/repeat')
      .flush('the code R3', { status: 409, statusText: 'Conflict' });

    await expect(pending).rejects.toBeInstanceOf(Error);
  });

  it('erases a rectangle without touching what it was drawn for', async () => {
    const pending = api.eraseDrawing('c1', 'd1');
    const request = http.expectOne('/api/companies/c1/stock-drawings/d1');
    expect(request.request.method).toBe('DELETE');
    request.flush(null, { status: 204, statusText: 'No Content' });

    await expect(pending).resolves.toBeUndefined();
  });

  it('says a floor is in use when it still carries rectangles', async () => {
    const pending = api.deleteFloor('c1', 'f1');
    const request = http.expectOne('/api/companies/c1/stock-floors/f1');
    expect(request.request.method).toBe('DELETE');
    request.flush('drawn', { status: 409, statusText: 'Conflict' });

    await expect(pending).rejects.toMatchObject({ code: 'in_use' });
  });
});
