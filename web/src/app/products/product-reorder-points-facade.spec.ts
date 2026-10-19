// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { ProductReorderPoints } from './product-reorder-points-facade';
import { ProductsApi, ProductsRefused } from './products-api';
import { ProductsFacade } from './products-facade';
import type { ProductReorderPointRow } from './products-types';

const row = (quantity: string | null): ProductReorderPointRow => ({
  establishmentId: 'e1',
  establishmentCode: 'SIEGE',
  establishmentName: 'Siège',
  quantity,
});

describe('ProductReorderPoints', () => {
  const api = { reorderPoints: vi.fn(), setReorderPoint: vi.fn() };
  const products = { versionMoved: vi.fn() };
  let facade: ProductReorderPoints;

  beforeEach(() => {
    api.reorderPoints.mockReset().mockResolvedValue([row('12.000')]);
    api.setReorderPoint.mockReset().mockResolvedValue(5);
    products.versionMoved.mockReset();
    TestBed.configureTestingModule({
      providers: [
        ProductReorderPoints,
        { provide: ProductsApi, useValue: api },
        { provide: ProductsFacade, useValue: products },
      ],
    });
    facade = TestBed.inject(ProductReorderPoints);
  });

  it('sets a point on the product version read and reads the points again', async () => {
    expect(await facade.set('c1', 'p1', 'e1', '12', 4)).toBe('saved');

    expect(api.setReorderPoint).toHaveBeenCalledWith('c1', 'p1', 'e1', '12', 4);
    expect(facade.rows()).toEqual([row('12.000')]);
    expect(facade.error()).toBeNull();
  });

  /** A change straight after sends the version this one left, without waiting for the product to be read again. */
  it('hands the version a write left to the product before reading the points again', async () => {
    await facade.set('c1', 'p1', 'e1', '12', 4);

    expect(products.versionMoved).toHaveBeenCalledWith('p1', 5);
    const moved = products.versionMoved.mock.invocationCallOrder[0];
    const readAgain = api.reorderPoints.mock.invocationCallOrder.at(-1);
    expect(moved).toBeLessThan(readAgain!);
  });

  it('keeps a refusal to say', async () => {
    api.setReorderPoint.mockRejectedValue(new ProductsRefused('invalid'));

    expect(await facade.set('c1', 'p1', 'e1', '12', 4)).toBe('refused');

    expect(facade.error()).toBe('invalid');
  });

  /** The product page says it and reads the product again; the points are read again here, saying nothing. */
  it('reads the points again after a product saved since, without keeping it as a refusal', async () => {
    api.setReorderPoint.mockRejectedValue(new ProductsRefused('record_changed'));
    api.reorderPoints.mockResolvedValue([row('20.000')]);

    expect(await facade.set('c1', 'p1', 'e1', '12', 4)).toBe('changed');

    expect(facade.error()).toBeNull();
    expect(facade.rows()).toEqual([row('20.000')]);
    expect(facade.busy()).toBe(false);
  });

  it('clears a point on the product version read and reads the points again', async () => {
    api.reorderPoints.mockResolvedValue([row(null)]);

    expect(await facade.set('c1', 'p1', 'e1', null, 4)).toBe('saved');

    expect(api.setReorderPoint).toHaveBeenCalledWith('c1', 'p1', 'e1', null, 4);
    expect(facade.rows()).toEqual([row(null)]);
    expect(products.versionMoved).toHaveBeenCalledWith('p1', 5);
  });
});
