// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { AuthFacade } from '../auth/auth-facade';
import { Feedback } from '../shared/feedback/feedback';
import { FormatFacade } from '../shared/i18n/format-facade';
import { ProductsApi, ProductsRefused } from './products-api';
import type { ProductRow } from './products-types';

/**
 * What a receipt did to the margins of the products it moved, said to whoever made it (docs/SPEC.md § 7, cost and
 * price): a cost moved under a kept price is never silent. Every facade that records a receipt calls it, so no screen
 * receiving goods can forget to; a cost reader hears the margin before and after, and one who writes products may
 * adjust the price from the toast. Its failures are quiet: the receipt is recorded, and the product page and « À
 * surveiller » still show the move.
 */
@Injectable({ providedIn: 'root' })
export class PriceReviewOffer {
  private readonly api = inject(ProductsApi);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);
  private readonly format = inject(FormatFacade);

  /** Reads the products a receipt moved and says each margin it moved, one toast per product. */
  async after(companyId: string, productIds: readonly string[]): Promise<void> {
    if (!this.auth.hasPermission('product.cost.read')) return;
    const products = await Promise.all(
      [...new Set(productIds)].map((id) => this.api.product(companyId, id).catch(() => null)),
    );
    for (const product of products) {
      if (product?.priceReview != null) this.say(companyId, product);
    }
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
      run: () => void this.adjust(companyId, product),
    });
  }

  private async adjust(companyId: string, product: ProductRow): Promise<void> {
    try {
      const adjusted = await this.api.settlePriceReview(
        companyId,
        product.id,
        'adjust',
        product.version,
      );
      this.feedback.effect(
        'products.price_review.adjusted',
        { name: adjusted.name, price: this.format.amount(adjusted.unitPriceNet, null) },
        'corrigeable',
      );
    } catch (error) {
      const code = error instanceof ProductsRefused ? error.code : 'network';
      this.feedback.failure(`products.errors.${code}`);
    }
  }
}
