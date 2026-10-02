// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { FormatFacade } from '../shared/i18n/format-facade';
import { Session } from '../shared/session/session';
import { ProductLabelsPage } from './product-labels-page';
import { ProductsApi } from './products-api';
import type { ProductRow, ProductScan } from './products-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({});
  }
}

const product = {
  id: 'p1',
  reference: 'VIS-6X40',
  name: 'Vis 6x40',
  barcodes: [
    { role: 'supplier', code: 'FOURN-1', quantity: 1, supplierId: 's1' },
    { role: 'unit', code: '4006381333931', quantity: 1, supplierId: null },
    { role: 'pack', code: '14006381333938', quantity: 12, supplierId: null },
  ],
} as unknown as ProductRow;

const scanOf = (code: string, role: 'unit' | 'pack', quantity: number): ProductScan => ({
  productId: 'p1',
  reference: 'VIS-6X40',
  name: 'Vis 6x40',
  isActive: true,
  code,
  role,
  quantity,
  lot: null,
  useBy: null,
  serial: null,
  unitPriceNet: '1.0000',
  unitPriceGross: '1.190',
  priceGross: role === 'pack' ? '14.280' : '1.190',
});

describe('ProductLabelsPage', () => {
  const api = { product: vi.fn(), scan: vi.fn() };
  const print = vi.fn();
  let fixture: ComponentFixture<ProductLabelsPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const all = (testId: string): HTMLElement[] => [
    ...fixture.nativeElement.querySelectorAll(`[data-testid="${testId}"]`),
  ];

  async function open(inputs: Record<string, string> = {}): Promise<void> {
    fixture = TestBed.createComponent(ProductLabelsPage);
    fixture.componentRef.setInput('productId', 'p1');
    for (const [name, value] of Object.entries(inputs)) fixture.componentRef.setInput(name, value);
    fixture.detectChanges();
    await fixture.whenStable();
    // The price is asked once the product is read: one more turn for that answer.
    await new Promise((resolve) => setTimeout(resolve));
    fixture.detectChanges();
  }

  beforeEach(() => {
    api.product.mockReset().mockResolvedValue(product);
    api.scan
      .mockReset()
      .mockImplementation(async (_company: string, code: string) =>
        code.startsWith('1') ? scanOf(code, 'pack', 12) : scanOf(code, 'unit', 1),
      );
    print.mockReset();
    TestBed.configureTestingModule({
      imports: [ProductLabelsPage],
      providers: [
        provideTranslateService(),
        provideTranslateLoader(StaticLoader),
        { provide: ProductsApi, useValue: api },
        {
          provide: Session,
          useValue: { me: () => ({ user: { id: 'u1' }, company: { id: 'c1', currency: 'TND' } }) },
        },
        { provide: FormatFacade, useValue: { amount: (value: string) => `~${value}` } },
      ],
    });
    vi.spyOn(TestBed.inject(DOCUMENT).defaultView!, 'print').mockImplementation(print);
  });

  it("prints the product's unit code by default: its name, reference, shelf price and the barcode", async () => {
    await open();
    expect(api.product).toHaveBeenCalledWith('c1', 'p1');
    expect(api.scan).toHaveBeenCalledWith('c1', '4006381333931');
    const [label] = all('product-label');
    expect(label.textContent).toContain('Vis 6x40');
    expect(label.textContent).toContain('VIS-6X40');
    expect(label.textContent).toContain('~1.190 TND');
    expect(label.textContent).toContain('4006381333931');
    expect(label.querySelector('svg[role="img"]')?.getAttribute('aria-label')).toBe(
      '4006381333931',
    );
    expect(all('product-label')).toHaveLength(1);
  });

  it('prints the code asked for, a pack at the price of the pack', async () => {
    await open({ code: '14006381333938' });
    const [label] = all('product-label');
    expect(label.textContent).toContain('~14.280 TND');
    expect(label.querySelector('svg[role="img"]')?.getAttribute('aria-label')).toBe(
      '14006381333938',
    );
  });

  it('lists every code of the product and prints a label for each one ticked, each at its own price', async () => {
    await open();
    expect(all('product-label')).toHaveLength(1);
    expect(q('product-labels-codes')?.textContent).toContain('FOURN-1');
    expect(q('product-labels-codes')?.textContent).toContain('14006381333938');

    const input = (code: string) =>
      q(`product-labels-code-${code}`)!.querySelector('input') as HTMLInputElement;
    expect(input('4006381333931').checked).toBe(true);
    expect(input('14006381333938').checked).toBe(false);

    input('14006381333938').click();
    fixture.detectChanges();
    const labels = all('product-label');
    expect(labels).toHaveLength(2);
    expect(labels[0].textContent).toContain('4006381333931');
    expect(labels[0].textContent).toContain('~1.190 TND');
    expect(labels[1].textContent).toContain('14006381333938');
    expect(labels[1].textContent).toContain('~14.280 TND');
    // The pack says how many pieces it stands for; the unit label says nothing of the kind.
    expect(labels[1].textContent).toContain('products.labels.pack_of');
    expect(labels[0].textContent).not.toContain('products.labels.pack_of');

    // Copies multiply every ticked code, and unticking the first leaves the second alone.
    const copies = q('product-labels-copies') as HTMLInputElement;
    copies.value = '2';
    copies.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    expect(all('product-label')).toHaveLength(4);
    input('4006381333931').click();
    fixture.detectChanges();
    expect(all('product-label')).toHaveLength(2);
    expect(all('product-label')[0].textContent).toContain('14006381333938');

    input('14006381333938').click();
    fixture.detectChanges();
    expect(all('product-label')).toHaveLength(0);
    expect((q('product-labels-print') as HTMLButtonElement).disabled).toBe(true);
  });

  it('prints as many copies as asked, and prints on asking with the controls left off the paper', async () => {
    await open();
    const copies = q('product-labels-copies') as HTMLInputElement;
    copies.value = '3';
    copies.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    expect(all('product-label')).toHaveLength(3);

    expect(q('product-labels-controls')?.classList.contains('print:hidden')).toBe(true);
    q('product-labels-print')!.click();
    expect(print).toHaveBeenCalled();
  });

  it('says so, and prints nothing, for a product that answers to no code of its own', async () => {
    api.product.mockResolvedValue({
      ...product,
      barcodes: [{ role: 'supplier', code: 'FOURN-1', quantity: 1, supplierId: 's1' }],
    });
    await open();
    expect(q('product-labels-no-code')).not.toBeNull();
    expect(all('product-label')).toHaveLength(0);
  });
});
