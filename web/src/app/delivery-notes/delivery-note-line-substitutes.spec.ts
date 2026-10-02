// SPDX-License-Identifier: AGPL-3.0-or-later

import { ComponentFixture, TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { ProductsApi } from '../products/products-api';
import type { ProductSubstituteRow } from '../products/products-types';
import { LineSubstitutes } from './delivery-note-line-substitutes';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      delivery_notes: {
        lines: {
          substitutes: { short: 'Seulement {{quantity}}', use: '{{reference}} ({{quantity}})' },
        },
      },
    });
  }
}

const sub = (reference: string, isActive = true): ProductSubstituteRow => ({
  id: `id-${reference}`,
  reference,
  name: reference,
  isActive,
  unitPriceNet: '1.0000',
  onHand: null,
});

// docs/SPEC.md § 7: a line whose product is short offers the substitutes that have what it asks.
describe('LineSubstitutes', () => {
  const api = { substitutes: vi.fn(), stockTotals: vi.fn() };
  let fixture: ComponentFixture<LineSubstitutes>;
  const q = (id: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${id}"]`);

  async function open(
    quantity: string,
    totals: Record<string, string> | null,
    rows = [sub('B'), sub('C')],
  ) {
    api.substitutes.mockResolvedValue(rows);
    if (totals === null) api.stockTotals.mockRejectedValue(new Error('403'));
    else api.stockTotals.mockResolvedValue(new Map(Object.entries(totals)));
    fixture = TestBed.createComponent(LineSubstitutes);
    fixture.componentRef.setInput('companyId', 'c1');
    fixture.componentRef.setInput('productId', 'p1');
    fixture.componentRef.setInput('quantity', quantity);
    fixture.detectChanges();
    // The effect starts the load; its promises are the component's own, which whenStable does not wait for.
    for (let turn = 0; turn < 10; turn++) await Promise.resolve();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    api.substitutes.mockReset();
    api.stockTotals.mockReset();
    TestBed.configureTestingModule({
      imports: [LineSubstitutes],
      providers: [
        provideTranslateService({ lang: 'fr', loader: provideTranslateLoader(StaticLoader) }),
        { provide: ProductsApi, useValue: api },
      ],
    });
  });

  it('offers only the active substitutes that have the quantity asked, when the product is short', async () => {
    await open('5', { p1: '2.000', 'id-B': '9.000', 'id-C': '3.000' });

    expect(q('line-substitute-B')).not.toBeNull();
    expect(q('line-substitute-C')).toBeNull();
    const swapped: string[] = [];
    fixture.componentInstance.swap.subscribe((id) => swapped.push(id));
    (q('line-substitute-B') as HTMLButtonElement).click();
    expect(swapped).toEqual(['id-B']);
  });

  it('offers nothing when the product has enough, or when stock cannot be read', async () => {
    await open('5', { p1: '5.000', 'id-B': '9.000' });
    expect(q('line-substitutes')).toBeNull();

    await open('5', null);
    expect(q('line-substitutes')).toBeNull();
  });

  it('offers nothing for an inactive substitute or one nothing is known of', async () => {
    await open('5', { p1: '0.000', 'id-B': '9.000' }, [sub('B', false), sub('D')]);

    expect(q('line-substitutes')).toBeNull();
  });
});
