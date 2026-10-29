// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { FormatFacade } from '../shared/i18n/format-facade';
import { Session } from '../shared/session/session';
import { ProductLabelsSheetPage } from './product-labels-sheet-page';
import { ProductsApi, ProductsRefused } from './products-api';
import type { LabelledProduct } from './products-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({});
  }
}

const screws: LabelledProduct = {
  productId: 'p1',
  reference: 'VIS-6X40',
  name: 'Vis 6x40',
  codes: [
    { role: 'unit', code: '4006381333931', quantity: 1, priceGross: '1.190' },
    { role: 'pack', code: '14006381333938', quantity: 12, priceGross: '14.280' },
    { role: 'supplier', code: 'FOURN-1', quantity: 1, priceGross: '1.190' },
  ],
};
const boxes: LabelledProduct = {
  productId: 'p2',
  reference: 'BOITE-12',
  name: 'Boîte de 12',
  codes: [{ role: 'pack', code: '14006381333945', quantity: 12, priceGross: '20.000' }],
};
const bolts: LabelledProduct = {
  productId: 'p3',
  reference: 'BOULON',
  name: 'Boulon',
  codes: [{ role: 'supplier', code: 'FOURN-9', quantity: 1, priceGross: '3.000' }],
};

describe('ProductLabelsSheetPage', () => {
  const api = { labels: vi.fn() };
  const print = vi.fn();
  let fixture: ComponentFixture<ProductLabelsSheetPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const all = (testId: string): HTMLElement[] => [
    ...fixture.nativeElement.querySelectorAll(`[data-testid="${testId}"]`),
  ];

  async function open(id: string | readonly string[] | undefined): Promise<void> {
    fixture = TestBed.createComponent(ProductLabelsSheetPage);
    fixture.componentRef.setInput('id', id);
    fixture.detectChanges();
    await fixture.whenStable();
    await new Promise((resolve) => setTimeout(resolve));
    fixture.detectChanges();
  }

  beforeEach(() => {
    api.labels.mockReset().mockResolvedValue([screws, boxes, bolts]);
    print.mockReset();
    TestBed.configureTestingModule({
      imports: [ProductLabelsSheetPage],
      providers: [
        provideRouter([]),
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

  it('prints one label per product, in the order selected, with the code its own sheet carries by default', async () => {
    await open(['p1', 'p2', 'p3', 'p1']);

    expect(api.labels).toHaveBeenCalledExactlyOnceWith('c1', ['p1', 'p2', 'p3']);
    const labels = all('product-label');
    expect(labels).toHaveLength(2);
    expect(labels[0]!.textContent).toContain('Vis 6x40');
    expect(labels[0]!.textContent).toContain('~1.190 TND');
    expect(labels[0]!.textContent).toContain('4006381333931');
    // A product with a pack code alone prints the pack's, at the pack's price.
    expect(labels[1]!.textContent).toContain('Boîte de 12');
    expect(labels[1]!.textContent).toContain('~20.000 TND');
    expect(labels[1]!.textContent).toContain('14006381333945');
  });

  it('names each product that gets no label, and why, above the labels and off the paper', async () => {
    api.labels.mockResolvedValue([screws, bolts]);
    await open(['p1', 'gone', 'p3']);

    const leftOut = q('product-labels-left-out');
    expect(leftOut?.classList.contains('print:hidden')).toBe(true);
    expect(leftOut?.textContent).toContain('products.labels.left_out');
    expect(q('product-labels-left-out-gone')?.textContent).toContain('products.labels.unknown');
    expect(q('product-labels-left-out-gone')?.textContent).toContain(
      'products.labels.reasons.not_found',
    );
    expect(q('product-labels-left-out-p3')?.textContent).toContain('BOULON · Boulon');
    expect(q('product-labels-left-out-p3')?.textContent).toContain(
      'products.labels.reasons.no_code',
    );
    expect(q('product-labels-left-out-p1')).toBeNull();
    expect(all('product-label')).toHaveLength(1);
  });

  it('names nothing left out when every product gets its label', async () => {
    api.labels.mockResolvedValue([screws]);
    await open('p1');

    expect(api.labels).toHaveBeenCalledExactlyOnceWith('c1', ['p1']);
    expect(q('product-labels-left-out')).toBeNull();
    expect(all('product-label')).toHaveLength(1);
  });

  it('prints as many copies of each as asked, within the ceiling, and prints through the browser', async () => {
    await open(['p1', 'p2']);
    const copies = q('product-labels-copies') as HTMLInputElement;

    copies.value = '3';
    copies.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    expect(all('product-label')).toHaveLength(6);

    q('product-labels-print')!.click();
    expect(print).toHaveBeenCalledTimes(1);
  });

  // A refusal said, never a silent clamp: « 150 » printed 100 and « 0 » printed one, with nothing said either time.
  it.each([
    ['150', 'form.errors.max'],
    ['0', 'form.errors.min'],
    ['', 'form.errors.required'],
    // A text field with the numeric keyboard (row 242): « 1,5 » reaches the form as one and a half, said not whole.
    ['2.5', 'import.rejections.not_a_whole_number'],
    ['1,5', 'import.rejections.not_a_whole_number'],
  ])('keeps « %s » in the field, says why under it and prints nothing', async (typed, said) => {
    await open(['p1', 'p2']);
    const copies = q('product-labels-copies') as HTMLInputElement;
    copies.value = '3';
    copies.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    copies.value = typed;
    copies.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    expect(copies.value).toBe(typed);
    // The sheet keeps the last count it could print until the field holds another.
    expect(all('product-label')).toHaveLength(6);
    expect(copies.type).toBe('text');

    q('product-labels-print')!.click();
    fixture.detectChanges();
    expect(print).not.toHaveBeenCalled();
    expect(q('product-labels-copies-error')?.textContent).toContain(said);
    expect(document.activeElement).toBe(copies);
  });

  it('prints nothing when no product selected has a code of its own', async () => {
    api.labels.mockResolvedValue([bolts]);
    await open(['p3']);

    expect(all('product-label')).toHaveLength(0);
    expect((q('product-labels-print') as HTMLButtonElement).disabled).toBe(true);
  });

  it('says why the labels could not be read', async () => {
    api.labels.mockRejectedValue(new ProductsRefused('network'));
    await open(['p1']);

    expect(q('product-labels-error')?.textContent).toContain('products.errors.network');
    expect(all('product-label')).toHaveLength(0);
    expect(q('product-labels-left-out')).toBeNull();
  });

  it('asks nothing when no product is named, and leads back to the list', async () => {
    await open(undefined);

    expect(api.labels).not.toHaveBeenCalled();
    expect(q('product-labels-back')?.getAttribute('href')).toBe('/products');
  });
});
