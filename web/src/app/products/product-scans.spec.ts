// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { AuthFacade } from '../auth/auth-facade';
import { ProductScans } from './product-scans';
import { ProductsApi, ProductsRefused } from './products-api';
import type { ProductScan } from './products-types';

const pack: ProductScan = {
  productId: 'p1',
  reference: 'ART-001',
  name: 'Pâte',
  isActive: true,
  code: '13017620422000',
  role: 'pack',
  quantity: 12,
  lot: null,
  useBy: null,
  serial: null,
};

describe('ProductScans', () => {
  const scan = vi.fn();

  function scans(): ProductScans {
    TestBed.configureTestingModule({
      providers: [
        { provide: ProductsApi, useValue: { scan } },
        { provide: AuthFacade, useValue: { me: () => ({ company: { id: 'c1' } }) } },
      ],
    });
    return TestBed.inject(ProductScans);
  }

  beforeEach(() => scan.mockReset());

  it('answers the pieces a pack enters for the product it was picked for', async () => {
    scan.mockResolvedValue(pack);

    expect(await scans().piecesPerScan('13017620422000', 'p1')).toBe(12);
    expect(scan).toHaveBeenCalledWith('c1', '13017620422000');
  });

  it('answers nothing for a unit code, another product, no product or a failed lookup', async () => {
    const service = scans();
    scan.mockResolvedValueOnce({ ...pack, role: 'unit', quantity: 1 });
    expect(await service.piecesPerScan('3017620422003', 'p1')).toBeNull();
    scan.mockResolvedValueOnce(pack);
    expect(await service.piecesPerScan('13017620422000', 'p2')).toBeNull();
    scan.mockResolvedValueOnce(null);
    expect(await service.piecesPerScan('999', 'p1')).toBeNull();
    // The line keeps the quantity it has: the product is already on it, and a count unknown is not a count of one.
    scan.mockRejectedValueOnce(new ProductsRefused('network'));
    expect(await service.piecesPerScan('13017620422000', 'p1')).toBeNull();
  });

  it('names what a scan reads for a document line, and lets a failed lookup fail rather than read as unknown', async () => {
    const service = scans();
    scan.mockResolvedValueOnce(pack);
    expect(await service.named('13017620422000')).toBe(pack);
    scan.mockResolvedValueOnce(null);
    expect(await service.named('999')).toBeNull();
    scan.mockRejectedValueOnce(new ProductsRefused('network'));
    await expect(service.named('13017620422000')).rejects.toBeInstanceOf(ProductsRefused);
  });
});
