// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { provideQuietFeedback, successToasts } from '../shared/testing/feedback';
import { ProductBarcodes } from './product-barcodes-facade';
import { ProductBarcodesSection } from './product-barcodes';
import type { BarcodesRefusal, ProductBarcode } from './products-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({});
  }
}

const unit: ProductBarcode = { role: 'unit', code: '3017620422003', quantity: 1, supplierId: null };

describe('ProductBarcodesSection', () => {
  const refusal = signal<BarcodesRefusal | null>(null);
  const facade = {
    busy: signal(false).asReadonly(),
    refusal: refusal.asReadonly(),
    suppliers: signal([{ id: 'v1', label: 'F-001 · Sotupa' }]).asReadonly(),
    loadSuppliers: vi.fn(),
    save: vi.fn(),
    forget: vi.fn(),
  };
  const modules = new Set(['vendors']);
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasModule: (module: string) => modules.has(module),
    hasPermission: () => true,
  };
  let fixture: ComponentFixture<ProductBarcodesSection>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const all = (prefix: string): HTMLElement[] => [
    ...fixture.nativeElement.querySelectorAll(`[data-testid^="${prefix}"]`),
  ];

  async function open(saved: ProductBarcode[] = [unit], readOnly = false): Promise<void> {
    fixture = TestBed.createComponent(ProductBarcodesSection);
    fixture.componentRef.setInput('productId', 'p1');
    fixture.componentRef.setInput('saved', saved);
    fixture.componentRef.setInput('readOnly', readOnly);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  /** What a scanner does: types the code into the focused field and presses Enter, in a few milliseconds. */
  async function scan(code: string): Promise<void> {
    const field = q('product-barcode-scan') as HTMLInputElement;
    field.value = code;
    field.dispatchEvent(new Event('input'));
    field.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  const codes = (): string[] =>
    all('product-barcode-code-').map((input) => (input as HTMLInputElement).value);

  beforeEach(() => {
    refusal.set(null);
    modules.clear();
    modules.add('vendors');
    facade.loadSuppliers.mockReset().mockResolvedValue(undefined);
    facade.save.mockReset().mockResolvedValue(true);
    facade.forget.mockReset();
    TestBed.configureTestingModule({
      imports: [ProductBarcodesSection],
      providers: [
        provideTranslateService(),
        provideTranslateLoader(StaticLoader),
        provideQuietFeedback(),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: AuthFacade, useValue: auth },
      ],
    }).overrideComponent(ProductBarcodesSection, {
      set: { providers: [{ provide: ProductBarcodes, useValue: facade }] },
    });
  });

  it('lists the saved codes and saves nothing while nothing changed', async () => {
    await open();

    expect(codes()).toEqual(['3017620422003']);
    expect((q('product-barcodes-save') as HTMLButtonElement).disabled).toBe(true);
    expect(facade.loadSuppliers).toHaveBeenCalledWith('c1');
  });

  it('adds a scanned code, keeps the scan field ready for the next, and saves the list', async () => {
    await open();

    await scan('10012345678902');

    expect(codes()).toEqual(['3017620422003', '10012345678902']);
    expect((q('product-barcode-scan') as HTMLInputElement).value).toBe('');
    // The product already has its unit code, so the new one starts as an internal code, one choice from a pack.
    expect(q('product-barcode-role-1')?.textContent).toContain('products.barcodes.roles.internal');

    (q('product-barcodes-save') as HTMLButtonElement).click();
    await fixture.whenStable();

    expect(facade.save).toHaveBeenCalledWith('c1', 'p1', [
      unit,
      { role: 'internal', code: '10012345678902', quantity: 1, supplierId: null },
    ]);
    expect(successToasts()).toContain('products.barcodes.saved');
  });

  it('says a code is already in the list rather than adding it twice', async () => {
    await open();

    await scan('03017620422003');

    expect(codes()).toEqual(['3017620422003']);
    expect(q('product-barcodes-duplicate')?.textContent).toContain('products.barcodes.duplicate');
  });

  it('refuses to save a row that is wrong, naming what is wrong under it', async () => {
    await open([]);

    await scan('3017620422004');

    expect(q('product-barcode-problem-0')?.textContent).toContain(
      'products.barcodes.problems.check_digit',
    );
    expect((q('product-barcodes-save') as HTMLButtonElement).disabled).toBe(true);
  });

  it('puts the refusal of a code another product holds under its row, naming that product', async () => {
    await open([]);
    await scan('X-1');
    refusal.set({ code: 'barcode_taken', index: 0, field: 'code', heldBy: 'ART-001' });
    fixture.detectChanges();

    expect(q('product-barcode-problem-0')?.textContent).toContain('products.barcodes.taken');
  });

  it('removes a row and puts the saved list back', async () => {
    await open();

    (q('product-barcode-remove-0') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(codes()).toEqual([]);

    (q('product-barcodes-revert') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(codes()).toEqual(['3017620422003']);
  });

  it('offers a supplier role only where there are suppliers to name', async () => {
    modules.clear();
    await open();

    expect(facade.loadSuppliers).not.toHaveBeenCalled();
  });

  it('shows the codes without a way to change them to a reader', async () => {
    await open([unit], true);

    expect(q('product-barcode-scan')).toBeNull();
    expect(q('product-barcodes-save')).toBeNull();
    expect(q('product-barcodes-readonly')?.textContent).toContain('3017620422003');
  });
});
