// SPDX-License-Identifier: AGPL-3.0-or-later

import { ComponentFixture, TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { FormatFacade } from '../shared/i18n/format-facade';
import { ProductPriceReviewNotice } from './product-price-review';
import type { PriceReviewDecision, ProductPriceReview } from './products-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      products: {
        price_review: {
          margin: 'Marge : {{before}} % → {{after}} %',
          why: 'Coût {{previousCost}} → {{cost}} {{currency}}, prix {{price}} {{currency}}',
          adjust: 'Ajuster le prix à {{price}} {{currency}}',
          keep: 'Garder le prix',
        },
      },
    });
  }
}

const review: ProductPriceReview = {
  previousCost: '12.0000',
  cost: '13.0000',
  price: '18.4620',
  suggestedPrice: '20.001',
  previousMargin: '35.0',
  margin: '29.6',
};

// docs/SPEC.md § 7, cost and price: a margin a receipt moved is shown, before and now, until someone settles it.
describe('ProductPriceReviewNotice', () => {
  let fixture: ComponentFixture<ProductPriceReviewNotice>;

  async function render(
    mayDecide: boolean,
    given: ProductPriceReview = review,
  ): Promise<HTMLElement> {
    TestBed.configureTestingModule({
      imports: [ProductPriceReviewNotice],
      providers: [
        provideTranslateService({ lang: 'fr', loader: provideTranslateLoader(StaticLoader) }),
        {
          provide: FormatFacade,
          useValue: {
            decimal: (value: string) => value.replace('.', ','),
            amount: (value: string, scale: number | null) =>
              Number(value)
                .toFixed(scale ?? 2)
                .replace('.', ','),
          },
        },
      ],
    });
    fixture = TestBed.createComponent(ProductPriceReviewNotice);
    fixture.componentRef.setInput('review', given);
    fixture.componentRef.setInput('scale', 3);
    fixture.componentRef.setInput('currency', 'TND');
    fixture.componentRef.setInput('mayDecide', mayDecide);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  const text = (root: HTMLElement, id: string) =>
    root.querySelector(`[data-testid="${id}"]`)?.textContent?.trim() ?? null;

  it('says the margin before and now, and why, as the screen writes figures', async () => {
    const root = await render(true);

    expect(text(root, 'product-price-review-margin')).toBe('Marge : 35,0 % → 29,6 %');
    expect(text(root, 'product-price-review-why')).toBe(
      'Coût 12,000 → 13,000 TND, prix 18,462 TND',
    );
    expect(root.querySelector('[role="status"]')).not.toBeNull();
  });

  it('keeps the sign of a margin the cost turned negative', async () => {
    const root = await render(true, { ...review, cost: '20.0000', margin: '-8.3' });

    expect(text(root, 'product-price-review-margin')).toBe('Marge : 35,0 % → -8,3 %');
  });

  it('offers the price that keeps the margin, or the price as it is', async () => {
    const root = await render(true);
    const decided: PriceReviewDecision[] = [];
    fixture.componentInstance.decide.subscribe((decision) => decided.push(decision));

    expect(text(root, 'product-price-review-adjust')).toBe('Ajuster le prix à 20,001 TND');
    root.querySelector<HTMLButtonElement>('[data-testid="product-price-review-adjust"]')?.click();
    root.querySelector<HTMLButtonElement>('[data-testid="product-price-review-keep"]')?.click();

    expect(decided).toEqual(['adjust', 'keep']);
  });

  it('only shows the margin to someone who may not write products', async () => {
    const root = await render(false);

    expect(text(root, 'product-price-review-margin')).not.toBeNull();
    expect(root.querySelector('[data-testid="product-price-review-adjust"]')).toBeNull();
    expect(root.querySelector('[data-testid="product-price-review-keep"]')).toBeNull();
  });

  it('holds both choices while one is being saved', async () => {
    const root = await render(true);
    fixture.componentRef.setInput('busy', true);
    fixture.detectChanges();

    expect(
      root.querySelector<HTMLButtonElement>('[data-testid="product-price-review-adjust"]')
        ?.disabled,
    ).toBe(true);
    expect(
      root.querySelector<HTMLButtonElement>('[data-testid="product-price-review-keep"]')?.disabled,
    ).toBe(true);
  });
});
