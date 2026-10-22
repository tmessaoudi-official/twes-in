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

  /** Says each margin moved and each price followed, one toast per product, or a count when the answer folded them. */
  async said(effects: readonly SaidEffect[]): Promise<void> {
    const companyId = this.auth.me()?.company?.id;
    if (companyId === undefined || !this.auth.hasPermission('product.cost.read')) return;
    const moved = new Set<string>();
    const followed = new Map<string, string>();
    for (const effect of effects) {
      if (effect.id === null || effect.count !== undefined) {
        this.feedback.success(
          effect.kind === PRICE_FOLLOWED
            ? 'products.price_review.followed_many'
            : 'products.price_review.moved_many',
          { count: effect.count ?? 1 },
        );
      } else if (effect.kind === MARGIN_MOVED) {
        moved.add(effect.id);
      } else if (typeof effect.params['previousPrice'] === 'string') {
        followed.set(effect.id, effect.params['previousPrice']);
      }
    }
    await Promise.all([
      ...[...moved].map(async (id) => {
        const product = await this.api.product(companyId, id).catch(() => null);
        if (product?.priceReview != null) this.say(companyId, product);
      }),
      ...[...followed].map(async ([id, previousPrice]) => {
        const product = await this.api.product(companyId, id).catch(() => null);
        if (product === null) return;
        const params = {
          name: product.name,
          before: this.format.amount(previousPrice, null),
          after: this.format.amount(product.unitPriceNet, null),
        };
        if (!this.auth.hasPermission('product.write') || product.mergedInto !== null) {
          this.feedback.success('products.price_review.followed', params);
          return;
        }
        // Undo first: the server puts back the price the receipt replaced while the price is still the one it set.
        this.feedback.success('products.price_review.followed', params, {
          key: 'products.price_review.undo',
          run: () => void this.settle(companyId, product, 'restore'),
        });
      }),
    ]);
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
