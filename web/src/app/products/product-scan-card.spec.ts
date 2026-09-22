// SPDX-License-Identifier: AGPL-3.0-or-later

import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { FormatFacade } from '../shared/i18n/format-facade';
import { ProductScanCard } from './product-scan-card';
import { ProductsApi, ProductsRefused } from './products-api';
import type { ProductScan } from './products-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({});
  }
}

const pack: ProductScan = {
  productId: 'p1',
  reference: 'ART-001',
  name: 'Pâte à tartiner',
  isActive: true,
  code: '13017620422000',
  role: 'pack',
  quantity: 12,
  lot: 'LOT-7',
  useBy: '2027-05-31',
  serial: null,
};

describe('ProductScanCard', () => {
  const close = vi.fn();
  const scan = vi.fn();
  const modules = new Set(['products', 'inventory']);
  const permissions = new Set(['product.read', 'stock.read']);
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasModule: (module: string) => modules.has(module),
    hasPermission: (permission: string) => permissions.has(permission),
  };
  let fixture: ComponentFixture<ProductScanCard>;
  let navigate: ReturnType<typeof vi.spyOn>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function open(code = ']C10113017620422000'): Promise<void> {
    TestBed.overrideProvider(MAT_DIALOG_DATA, { useValue: { code } });
    fixture = TestBed.createComponent(ProductScanCard);
    navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  function press(key: string): void {
    q('product-scan-card')!.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));
    fixture.detectChanges();
  }

  beforeEach(() => {
    close.mockReset();
    scan.mockReset().mockResolvedValue(pack);
    modules.clear();
    ['products', 'inventory'].forEach((module) => modules.add(module));
    permissions.clear();
    ['product.read', 'stock.read'].forEach((permission) => permissions.add(permission));
    TestBed.configureTestingModule({
      imports: [ProductScanCard],
      providers: [
        provideRouter([]),
        provideTranslateService(),
        provideTranslateLoader(StaticLoader),
        { provide: MAT_DIALOG_DATA, useValue: { code: '' } },
        { provide: MatDialogRef, useValue: { close } },
        { provide: AuthFacade, useValue: auth },
        { provide: ProductsApi, useValue: { scan } },
        { provide: FormatFacade, useValue: { day: (value: string) => value } },
      ],
    });
  });

  it('shows what the scan names: the product, the pack it counts and what the GS1 scan carried', async () => {
    await open();

    expect(scan).toHaveBeenCalledWith('c1', ']C10113017620422000');
    const card = q('product-scan-card')!.textContent ?? '';
    expect(card).toContain('ART-001');
    expect(card).toContain('Pâte à tartiner');
    expect(q('product-scan-enters')?.textContent).toContain('products.scan.enters');
    expect(q('product-scan-lot')?.textContent).toContain('LOT-7');
    expect(q('product-scan-use-by')?.textContent).toContain('2027-05-31');
    expect(q('product-scan-serial')).toBeNull();
    expect(q('product-scan-retired')).toBeNull();
  });

  it('opens the product with Enter, its codes with C and its movements with M', async () => {
    await open();

    press('Enter');
    expect(navigate).toHaveBeenLastCalledWith('/products/p1');
    press('c');
    expect(navigate).toHaveBeenLastCalledWith('/products/p1?tab=codes');
    press('M');
    expect(navigate).toHaveBeenLastCalledWith('/stock/movements?productId=p1');
    expect(close).toHaveBeenCalledTimes(3);
  });

  it('offers no movements to somebody who may not read the stock', async () => {
    permissions.delete('stock.read');
    await open();

    expect(q('product-scan-action-movements')).toBeNull();
    press('m');
    expect(navigate).not.toHaveBeenCalled();
    expect(q('product-scan-action-sheet')).not.toBeNull();
  });

  it('says a retired product is retired', async () => {
    scan.mockResolvedValue({ ...pack, isActive: false });
    await open();

    expect(q('product-scan-retired')?.textContent).toContain('products.scan.retired');
  });

  it('says no product answers to the code, and Enter searches the catalogue for it', async () => {
    scan.mockResolvedValue(null);
    await open('ABC 12');

    expect(q('product-scan-none')?.textContent).toContain('products.scan.none');
    expect(q('product-scan-card')!.textContent).toContain('ABC 12');
    press('Enter');
    expect(navigate).toHaveBeenLastCalledWith('/products?q=ABC%2012');
  });

  it('says the lookup failed rather than that nothing matched', async () => {
    scan.mockRejectedValue(new ProductsRefused('network'));
    await open();

    expect(q('product-scan-failed')?.textContent).toContain('products.errors.network');
    expect(q('product-scan-none')).toBeNull();
  });
});
