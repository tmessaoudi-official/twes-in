// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { VendorsApi } from '../vendors/vendors-api';
import { ProductBarcodes } from './product-barcodes-facade';
import { BarcodesRefused, ProductsApi } from './products-api';
import { ProductsFacade } from './products-facade';
import type { ProductBarcode } from './products-types';

const unit: ProductBarcode = { role: 'unit', code: '3017620422003', quantity: 1, supplierId: null };

describe('ProductBarcodes', () => {
  const api = { replaceBarcodes: vi.fn() };
  const products = { barcodesSaved: vi.fn() };
  let facade: ProductBarcodes;

  beforeEach(() => {
    api.replaceBarcodes.mockReset();
    products.barcodesSaved.mockReset();
    TestBed.configureTestingModule({
      providers: [
        ProductBarcodes,
        { provide: ProductsApi, useValue: api },
        { provide: VendorsApi, useValue: {} },
        { provide: ProductsFacade, useValue: products },
      ],
    });
    facade = TestBed.inject(ProductBarcodes);
  });

  it('saves the codes on the version read and writes what they left into the product held', async () => {
    api.replaceBarcodes.mockResolvedValue({ barcodes: [unit], version: 4 });

    expect(await facade.save('c1', 'p1', [unit], 3)).toBe('saved');

    expect(api.replaceBarcodes).toHaveBeenCalledWith('c1', 'p1', [unit], 3);
    expect(products.barcodesSaved).toHaveBeenCalledWith('p1', [unit], 4);
    expect(facade.refusal()).toBeNull();
  });

  it('keeps a refusal to say under its row', async () => {
    const refusal = { code: 'barcode_taken' as const, index: 0, field: 'code', heldBy: 'ART-001' };
    api.replaceBarcodes.mockRejectedValue(new BarcodesRefused(refusal));

    expect(await facade.save('c1', 'p1', [unit], 3)).toBe('refused');

    expect(facade.refusal()).toEqual(refusal);
    expect(products.barcodesSaved).not.toHaveBeenCalled();
  });

  /** The product page says it and reads the product again; this tab says nothing of its own. */
  it('reports a product saved since without keeping it as a refusal', async () => {
    api.replaceBarcodes.mockRejectedValue(
      new BarcodesRefused({ code: 'record_changed', index: null, field: null, heldBy: null }),
    );

    expect(await facade.save('c1', 'p1', [unit], 3)).toBe('changed');

    expect(facade.refusal()).toBeNull();
    expect(facade.busy()).toBe(false);
  });
});
