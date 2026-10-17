// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { ProductReorderPoints } from './product-reorder-points-facade';
import { ProductsApi, ProductsRefused } from './products-api';
import type { ProductReorderPointRow } from './products-types';

const row = (quantity: string | null): ProductReorderPointRow => ({
  establishmentId: 'e1',
  establishmentCode: 'SIEGE',
  establishmentName: 'Siège',
  quantity,
});

describe('ProductReorderPoints', () => {
  const api = { reorderPoints: vi.fn(), setReorderPoint: vi.fn(), clearReorderPoint: vi.fn() };
  let facade: ProductReorderPoints;

  beforeEach(() => {
    api.reorderPoints.mockReset().mockResolvedValue([row('12.000')]);
    api.setReorderPoint.mockReset().mockResolvedValue(row('12.000'));
    api.clearReorderPoint.mockReset().mockResolvedValue(undefined);
    TestBed.configureTestingModule({
      providers: [ProductReorderPoints, { provide: ProductsApi, useValue: api }],
    });
    facade = TestBed.inject(ProductReorderPoints);
  });

  it('sets a point on the product version read and reads the points again', async () => {
    expect(await facade.set('c1', 'p1', 'e1', '12', 4)).toBe('saved');

    expect(api.setReorderPoint).toHaveBeenCalledWith('c1', 'p1', 'e1', '12', 4);
    expect(facade.rows()).toEqual([row('12.000')]);
    expect(facade.error()).toBeNull();
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

  it('clears a point and reads the points again', async () => {
    api.reorderPoints.mockResolvedValue([row(null)]);

    expect(await facade.clear('c1', 'p1', 'e1')).toBe('saved');

    expect(api.clearReorderPoint).toHaveBeenCalledWith('c1', 'p1', 'e1');
    expect(facade.rows()).toEqual([row(null)]);
  });
});
