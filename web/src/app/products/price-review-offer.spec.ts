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
import type { SaidEffect } from '../shared/effects/effects';

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
  unitPriceGross: null,
  margin: null,
  marginPercent: null,
  categoryId: null,
  barcodes: [],
  defaultTaxComponentIds: [],
  customFields: {},
  isActive: true,
  tracking: 'none',
  mainPhotoId: null,
  mergedInto: null,
  toCheck: false,
  checkNote: null,
  zeroPriceIntended: false,
  checkReasons: [],
  priceReview: {
    previousCost: '1000.0000',
    cost: '1100.0000',
    price: '1250.0000',
    suggestedPrice: '1375.000',
    previousMargin: '20.0',
    margin: '12.0',
  },
  vendorIds: [],
  vendors: [],
};
const unmoved: ProductRow = { ...moved, id: 'p2', priceReview: null };

function marginMoved(id: string): SaidEffect {
  return { kind: 'product.margin_moved', id, params: {} };
}

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
          useValue: {
            hasPermission: (name: string) => permissions.has(name),
            me: () => ({ company: { id: 'c1' } }),
          },
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
    await offer().said([marginMoved('p1'), marginMoved('p2'), marginMoved('p1')]);

    expect(api.product).toHaveBeenCalledTimes(2);
    expect(successToasts()).toEqual(['products.price_review.moved']);
    expect(said()[0]?.params).toEqual({ name: 'Perceuse', before: '20,0', after: '12,0' });
  });

  it('offers to adjust, which settles the review on the version read and says the new price', async () => {
    await offer().said([marginMoved('p1')]);
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
    await offer().said([marginMoved('p1')]);

    offeredNext()?.run();
    await vi.waitFor(() => expect(failureToasts()).toEqual(['products.errors.record_changed']));
  });

  it('only says the margin to someone who reads costs but does not write products', async () => {
    permissions.delete('product.write');
    await offer().said([marginMoved('p1')]);

    expect(successToasts()).toEqual(['products.price_review.moved']);
    expect(offeredNext()).toBeNull();
  });

  it('offers nothing to adjust on a product merged away', async () => {
    api.product.mockResolvedValue({
      ...moved,
      mergedInto: { id: 'p9', reference: 'ART-009', name: 'Perceuse 2' },
    });
    await offer().said([marginMoved('p1')]);

    expect(offeredNext()).toBeNull();
  });

  it('reads nothing and says nothing to someone who cannot read costs', async () => {
    permissions.delete('product.cost.read');
    await offer().said([marginMoved('p1')]);

    expect(api.product).not.toHaveBeenCalled();
    expect(said()).toEqual([]);
  });

  it('stays quiet about a product it could not read, the receipt being recorded', async () => {
    api.product.mockRejectedValue(new Error('offline'));
    await offer().said([marginMoved('p1')]);

    expect(said()).toEqual([]);
  });

  it('says a price that followed its cost, from the price it had to the one it has', async () => {
    api.product.mockResolvedValue({ ...unmoved, unitPriceNet: '1375.0000' });
    await offer().said([
      { kind: 'product.price_followed', id: 'p2', params: { previousPrice: '1250.0000' } },
    ]);

    expect(api.product).toHaveBeenCalledWith('c1', 'p2');
    expect(successToasts()).toEqual(['products.price_review.followed']);
    expect(said()[0]?.params).toEqual({
      name: 'Perceuse',
      before: '1250.0000 TND',
      after: '1375.0000 TND',
    });
  });

  it('offers « Annuler » on a price that followed its cost, which puts back the price it had and says it', async () => {
    api.product.mockResolvedValue({ ...unmoved, unitPriceNet: '1375.0000' });
    api.settlePriceReview.mockResolvedValue({ ...unmoved, unitPriceNet: '1250.0000' });
    await offer().said([
      { kind: 'product.price_followed', id: 'p2', params: { previousPrice: '1250.0000' } },
    ]);
    const next = offeredNext();
    expect(next?.key).toBe('products.price_review.undo');

    next?.run();
    await vi.waitFor(() =>
      expect(effectToasts()).toEqual(['products.price_review.restored:corrigeable']),
    );
    expect(api.settlePriceReview).toHaveBeenCalledWith('c1', 'p2', 'restore', 4);
    expect(said().at(-1)?.params).toEqual({ name: 'Perceuse', price: '1250.0000 TND' });
  });

  it('says why a followed price was not put back', async () => {
    api.product.mockResolvedValue({ ...unmoved, unitPriceNet: '1375.0000' });
    api.settlePriceReview.mockRejectedValue(new ProductsRefused('not_restorable', []));
    await offer().said([
      { kind: 'product.price_followed', id: 'p2', params: { previousPrice: '1250.0000' } },
    ]);

    offeredNext()?.run();
    await vi.waitFor(() => expect(failureToasts()).toEqual(['products.errors.not_restorable']));
  });

  it('offers no « Annuler » on a followed price to someone who does not write products, or on one merged away', async () => {
    permissions.delete('product.write');
    api.product.mockResolvedValue({ ...unmoved, unitPriceNet: '1375.0000' });
    const followed: SaidEffect = {
      kind: 'product.price_followed',
      id: 'p2',
      params: { previousPrice: '1250.0000' },
    };
    await offer().said([followed]);
    expect(successToasts()).toEqual(['products.price_review.followed']);
    expect(offeredNext()).toBeNull();

    permissions.add('product.write');
    api.product.mockResolvedValue({
      ...unmoved,
      mergedInto: { id: 'p9', reference: 'ART-009', name: 'Perceuse 2' },
    });
    await TestBed.inject(PriceReviewOffer).said([followed]);
    expect(offeredNext()).toBeNull();
  });

  it('says a count when the answer folded too many effects to name each product', async () => {
    await offer().said([
      { kind: 'product.price_followed', id: null, params: {}, count: 30 },
      { kind: 'product.margin_moved', id: null, params: {}, count: 2 },
    ]);

    expect(api.product).not.toHaveBeenCalled();
    // One toast: a second would replace the first before it is read.
    expect(successToasts()).toEqual(['products.price_review.followed_and_moved']);
    expect(said()[0]?.params).toEqual({ followed: 30, moved: 2 });
    expect(offeredNext()).toBeNull();
  });

  it('says one toast for a receipt whose prices followed their costs, whose « Annuler » puts them all back', async () => {
    api.product.mockImplementation(async (_company: string, id: string) => ({
      ...unmoved,
      id,
      version: id === 'p1' ? 4 : 7,
      unitPriceNet: '1375.0000',
    }));
    api.settlePriceReview.mockResolvedValue({ ...unmoved, unitPriceNet: '1250.0000' });
    await offer().said([
      { kind: 'product.price_followed', id: 'p1', params: { previousPrice: '1250.0000' } },
      { kind: 'product.price_followed', id: 'p2', params: { previousPrice: '900.0000' } },
    ]);

    expect(successToasts()).toEqual(['products.price_review.followed_many']);
    expect(said()[0]?.params).toEqual({ count: 2 });
    const next = offeredNext();
    expect(next?.key).toBe('products.price_review.undo');

    next?.run();
    await vi.waitFor(() =>
      expect(effectToasts()).toEqual(['products.price_review.restored_many:corrigeable']),
    );
    expect(api.settlePriceReview.mock.calls).toEqual([
      ['c1', 'p1', 'restore', 4],
      ['c1', 'p2', 'restore', 7],
    ]);
    expect(said().at(-1)?.params).toEqual({ count: 2 });
  });

  it('says one toast when a receipt moved a margin and a price both, its « Annuler » the price’s', async () => {
    api.product.mockImplementation(async (_company: string, id: string) =>
      id === 'p1' ? moved : { ...unmoved, unitPriceNet: '1375.0000' },
    );
    await offer().said([
      marginMoved('p1'),
      { kind: 'product.price_followed', id: 'p2', params: { previousPrice: '1250.0000' } },
    ]);

    expect(successToasts()).toEqual(['products.price_review.followed_and_moved']);
    expect(said()[0]?.params).toEqual({ followed: 1, moved: 1 });
    offeredNext()?.run();
    await vi.waitFor(() =>
      expect(api.settlePriceReview).toHaveBeenCalledWith('c1', 'p2', 'restore', 4),
    );
  });

  it('says why the prices were not all put back', async () => {
    api.product.mockImplementation(async (_company: string, id: string) => ({ ...unmoved, id }));
    api.settlePriceReview
      .mockResolvedValueOnce({ ...unmoved, unitPriceNet: '1250.0000' })
      .mockRejectedValueOnce(new ProductsRefused('not_restorable', []));
    await offer().said([
      { kind: 'product.price_followed', id: 'p1', params: { previousPrice: '1250.0000' } },
      { kind: 'product.price_followed', id: 'p2', params: { previousPrice: '900.0000' } },
    ]);

    offeredNext()?.run();
    await vi.waitFor(() => expect(failureToasts()).toEqual(['products.errors.not_restorable']));
    expect(effectToasts()).toEqual([]);
  });
});
