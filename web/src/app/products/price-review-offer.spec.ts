// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { AuthFacade } from '../auth/auth-facade';
import { FormatFacade } from '../shared/i18n/format-facade';
import {
  effectToasts,
  failureToasts,
  offeredNext,
  provideQuietFeedback,
  successToasts,
} from '../shared/testing/feedback';
import { Feedback } from '../shared/feedback/feedback';
import type { RecordedFeedback } from '../shared/testing/feedback';
import { PriceReviewOffer } from './price-review-offer';
import { ProductsApi, ProductsRefused } from './products-api';
import type { ProductRow } from './products-types';

const moved: ProductRow = {
  id: 'p1',
  version: 4,
  reference: 'ART-001',
  name: 'Perceuse',
  description: null,
  substitutionGroup: null,
  kind: 'goods',
  unitId: 'u1',
  unitPriceNet: '1250.0000',
  costPrice: '1100.0000',
  categoryId: null,
  barcodes: [],
  defaultTaxComponentIds: [],
  customFields: {},
  isActive: true,
  tracking: 'none',
  mainPhotoId: null,
  mergedInto: null,
  priceReview: {
    previousCost: '1000.0000',
    cost: '1100.0000',
    price: '1250.0000',
    suggestedPrice: '1375.000',
    previousMargin: '20.0',
    margin: '12.0',
  },
};
const unmoved: ProductRow = { ...moved, id: 'p2', priceReview: null };

describe('PriceReviewOffer', () => {
  let permissions: Set<string>;
  const api = { product: vi.fn(), settlePriceReview: vi.fn() };

  function offer(): PriceReviewOffer {
    TestBed.configureTestingModule({
      providers: [
        provideQuietFeedback(),
        { provide: ProductsApi, useValue: api },
        {
          provide: AuthFacade,
          useValue: { hasPermission: (name: string) => permissions.has(name) },
        },
        {
          provide: FormatFacade,
          useValue: {
            decimal: (value: string) => value.replace('.', ','),
            amount: (value: string) => `${value} TND`,
          },
        },
      ],
    });
    return TestBed.inject(PriceReviewOffer);
  }

  function said(): RecordedFeedback['said'] {
    return (TestBed.inject(Feedback) as RecordedFeedback).said;
  }

  beforeEach(() => {
    permissions = new Set(['product.cost.read', 'product.write']);
    api.product
      .mockReset()
      .mockImplementation(async (_company: string, id: string) => (id === 'p1' ? moved : unmoved));
    api.settlePriceReview.mockReset().mockResolvedValue({ ...moved, unitPriceNet: '1375.0000' });
  });

  it('says each margin a receipt moved, once per product, before and after', async () => {
    await offer().after('c1', ['p1', 'p2', 'p1']);

    expect(api.product).toHaveBeenCalledTimes(2);
    expect(successToasts()).toEqual(['products.price_review.moved']);
    expect(said()[0]?.params).toEqual({ name: 'Perceuse', before: '20,0', after: '12,0' });
  });

  it('offers to adjust, which settles the review on the version read and says the new price', async () => {
    await offer().after('c1', ['p1']);
    const next = offeredNext();
    expect(next?.key).toBe('products.price_review.adjust_short');

    next?.run();
    await vi.waitFor(() =>
      expect(effectToasts()).toEqual(['products.price_review.adjusted:corrigeable']),
    );
    expect(api.settlePriceReview).toHaveBeenCalledWith('c1', 'p1', 'adjust', 4);
    expect(said().at(-1)?.params).toEqual({ name: 'Perceuse', price: '1375.0000 TND' });
  });

  it('says why an adjustment was refused', async () => {
    api.settlePriceReview.mockRejectedValue(new ProductsRefused('record_changed', []));
    await offer().after('c1', ['p1']);

    offeredNext()?.run();
    await vi.waitFor(() => expect(failureToasts()).toEqual(['products.errors.record_changed']));
  });

  it('only says the margin to someone who reads costs but does not write products', async () => {
    permissions.delete('product.write');
    await offer().after('c1', ['p1']);

    expect(successToasts()).toEqual(['products.price_review.moved']);
    expect(offeredNext()).toBeNull();
  });

  it('offers nothing to adjust on a product merged away', async () => {
    api.product.mockResolvedValue({
      ...moved,
      mergedInto: { id: 'p9', reference: 'ART-009', name: 'Perceuse 2' },
    });
    await offer().after('c1', ['p1']);

    expect(offeredNext()).toBeNull();
  });

  it('reads nothing and says nothing to someone who cannot read costs', async () => {
    permissions.delete('product.cost.read');
    await offer().after('c1', ['p1']);

    expect(api.product).not.toHaveBeenCalled();
    expect(said()).toEqual([]);
  });

  it('stays quiet about a product it could not read, the receipt being recorded', async () => {
    api.product.mockRejectedValue(new Error('offline'));
    await offer().after('c1', ['p1']);

    expect(said()).toEqual([]);
  });
});
