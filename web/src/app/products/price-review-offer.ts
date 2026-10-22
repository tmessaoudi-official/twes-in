// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { AuthFacade } from '../auth/auth-facade';
import { Feedback } from '../shared/feedback/feedback';
import { FormatFacade } from '../shared/i18n/format-facade';
import { ProductsApi, ProductsRefused } from './products-api';
import type { ProductRow } from './products-types';
import type { SaidEffect } from '../shared/effects/effects';
import { MARGIN_MOVED, PRICE_FOLLOWED } from './price-effects';

/**
 * What a receipt did to the margins and prices of the products it moved, said to whoever made it (docs/SPEC.md § 7,
 * cost and price): a cost moved under a kept price, or a price that followed its cost, is never silent. The receipt's
 * own answer says it (`PriceEffects`), whichever screen received the goods, and only the receipt that moved it: one at
 * the same cost says nothing. A cost reader hears the margin before and after, or the price before and after, and one
 * who writes products may adjust a kept price, or put back a price that followed, from the toast. Its failures are
 * quiet: the receipt is recorded, and the product page and « À surveiller » still show the move.
 */
@Injectable({ providedIn: 'root' })
export class PriceReviewOffer {
  private readonly api = inject(ProductsApi);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);
  private readonly format = inject(FormatFacade);

  /**
   * Says what the receipt did in one toast, since a second replaces the first before it is read: the product itself
   * when one margin or one price moved, else how many, with one « Annuler » putting back every price that followed.
   */
  async said(effects: readonly SaidEffect[]): Promise<void> {
    const companyId = this.auth.me()?.company?.id;
    if (companyId === undefined || !this.auth.hasPermission('product.cost.read')) return;
    const moved = new Set<string>();
    const followed = new Map<string, string>();
    // What the answer folded names no product: it is counted, never offered.
    let folded = { followed: 0, moved: 0 };
    for (const effect of effects) {
      if (effect.id === null || effect.count !== undefined) {
        const count = effect.count ?? 1;
        folded =
          effect.kind === PRICE_FOLLOWED
            ? { ...folded, followed: folded.followed + count }
            : { ...folded, moved: folded.moved + count };
      } else if (effect.kind === MARGIN_MOVED) {
        moved.add(effect.id);
      } else if (typeof effect.params['previousPrice'] === 'string') {
        followed.set(effect.id, effect.params['previousPrice']);
      }
    }
    const read = (id: string) => this.api.product(companyId, id).catch(() => null);
    const [reviewed, prices] = await Promise.all([
      Promise.all([...moved].map(read)).then((rows) =>
        rows.filter((row): row is ProductRow => row?.priceReview != null),
      ),
      Promise.all(
        [...followed].map(async ([id, previousPrice]) => {
          const product = await read(id);
          return product === null ? null : { product, previousPrice };
        }),
      ).then((rows) => rows.filter((row) => row !== null)),
    ]);
    const counts = {
      followed: prices.length + folded.followed,
      moved: reviewed.length + folded.moved,
    };
    if (counts.followed + counts.moved === 0) return;
    const [onlyPrice] = prices;
    const [onlyReview] = reviewed;
    if (counts.moved === 0 && counts.followed === 1 && onlyPrice !== undefined) {
      this.sayFollowed(companyId, onlyPrice.product, onlyPrice.previousPrice);
      return;
    }
    if (counts.followed === 0 && counts.moved === 1 && onlyReview !== undefined) {
      this.say(companyId, onlyReview);
      return;
    }
    const message =
      counts.moved === 0
        ? { key: 'products.price_review.followed_many', params: { count: counts.followed } }
        : counts.followed === 0
          ? { key: 'products.price_review.moved_many', params: { count: counts.moved } }
          : { key: 'products.price_review.followed_and_moved', params: counts };
    const restorable = this.auth.hasPermission('product.write')
      ? prices.map(({ product }) => product).filter((product) => product.mergedInto === null)
      : [];
    if (restorable.length === 0) {
      this.feedback.success(message.key, message.params);
      return;
    }
    // Undo first: each price the receipt set goes back to the one it replaced, while it is still the one it set.
    this.feedback.success(message.key, message.params, {
      key: 'products.price_review.undo',
      run: () => void this.restoreAll(companyId, restorable),
    });
  }

  private sayFollowed(companyId: string, product: ProductRow, previousPrice: string): void {
    const params = {
      name: product.name,
      before: this.format.amount(previousPrice, null),
      after: this.format.amount(product.unitPriceNet, null),
    };
    if (!this.auth.hasPermission('product.write') || product.mergedInto !== null) {
      this.feedback.success('products.price_review.followed', params);
      return;
    }
    this.feedback.success('products.price_review.followed', params, {
      key: 'products.price_review.undo',
      run: () => void this.settle(companyId, product, 'restore'),
    });
  }

  /** Puts every followed price back, one after another; the first refusal is said, and what was put back stays so. */
  private async restoreAll(companyId: string, products: readonly ProductRow[]): Promise<void> {
    for (const product of products) {
      try {
        await this.api.settlePriceReview(companyId, product.id, 'restore', product.version);
      } catch (error) {
        const code = error instanceof ProductsRefused ? error.code : 'network';
        this.feedback.failure(`products.errors.${code}`);
        return;
      }
    }
    this.feedback.effect(
      'products.price_review.restored_many',
      { count: products.length },
      'corrigeable',
    );
  }

  private say(companyId: string, product: ProductRow): void {
    const review = product.priceReview;
    if (review === null) return;
    const params = {
      name: product.name,
      before: this.format.decimal(review.previousMargin),
      after: this.format.decimal(review.margin),
    };
    if (!this.auth.hasPermission('product.write') || product.mergedInto !== null) {
      this.feedback.success('products.price_review.moved', params);
      return;
    }
    this.feedback.success('products.price_review.moved', params, {
      key: 'products.price_review.adjust_short',
      run: () => void this.settle(companyId, product, 'adjust'),
    });
  }

  private async settle(
    companyId: string,
    product: ProductRow,
    decision: 'adjust' | 'restore',
  ): Promise<void> {
    try {
      const settled = await this.api.settlePriceReview(
        companyId,
        product.id,
        decision,
        product.version,
      );
      this.feedback.effect(
        decision === 'adjust' ? 'products.price_review.adjusted' : 'products.price_review.restored',
        { name: settled.name, price: this.format.amount(settled.unitPriceNet, null) },
        'corrigeable',
      );
    } catch (error) {
      const code = error instanceof ProductsRefused ? error.code : 'network';
      this.feedback.failure(`products.errors.${code}`);
    }
  }
}
