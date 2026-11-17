// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, input, output } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { FormatFacade } from '../shared/i18n/format-facade';
import { AmountPipe } from '../shared/i18n/format-pipes';
import type { MarginBasis } from '../shared/settings/settings-registry';
import type { PriceReviewDecision, ProductPriceReview } from './products-types';

/**
 * The margin a receipt's cost moved under a price that stayed (docs/SPEC.md § 7, cost and price): what it was and what
 * it is now, and the two ways to settle it, the price that keeps the margin or the price as it is. Shown until settled,
 * to whoever reads costs; only someone who writes products settles it.
 */
@Component({
  selector: 'app-product-price-review',
  imports: [AmountPipe, MatButtonModule, MatIconModule, TranslatePipe],
  templateUrl: './product-price-review.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProductPriceReviewNotice {
  private readonly format = inject(FormatFacade);

  readonly review = input.required<ProductPriceReview>();
  readonly scale = input.required<number>();
  readonly currency = input.required<string>();
  /** Whether this person may settle it, which writing products allows. */
  readonly mayDecide = input(false);
  readonly busy = input(false);
  /** Whether the person reads a margin as a share of the price or of the cost. */
  readonly basis = input<MarginBasis>('margin');
  readonly decide = output<PriceReviewDecision>();

  /** The moved margin as the person reads it: its sentence and its two figures. */
  protected moved(): { key: string; before: string; after: string } {
    const review = this.review();
    return this.basis() === 'markup'
      ? {
          key: 'products.price_review.markup',
          before: review.previousMarkup === null ? '—' : this.percent(review.previousMarkup),
          after: review.markup === null ? '—' : this.percent(review.markup),
        }
      : {
          key: 'products.price_review.margin',
          before: this.percent(review.previousMargin),
          after: this.percent(review.margin),
        };
  }

  /** A margin as the screen writes decimals, its sign kept: a cost above the price is a negative margin. */
  protected percent(value: string): string {
    return this.format.decimal(value);
  }
}
