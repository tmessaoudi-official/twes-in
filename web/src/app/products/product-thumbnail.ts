// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { AuthFacade } from '../auth/auth-facade';
import { productPhotoUrl } from './product-photo-url';
import type { PhotoSize } from './product-photos-types';

/**
 * A product's main photo where the product is picked or seen, or a plain mark where it has none, at a fixed size so a
 * row does not move when the picture arrives. Decorative: the product's name always stands beside it.
 */
@Component({
  selector: 'app-product-thumbnail',
  imports: [MatIconModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    class:
      'inline-flex shrink-0 items-center justify-center overflow-hidden rounded bg-surface-container',
    '[style.width.px]': 'side()',
    '[style.height.px]': 'side()',
  },
  template: `
    @if (src(); as address) {
      <img
        class="h-full w-full object-cover"
        [src]="address"
        alt=""
        loading="lazy"
        [width]="side()"
        [height]="side()"
        data-testid="product-thumbnail"
      />
    } @else {
      <span
        aria-hidden="true"
        class="inline-flex text-on-surface-variant"
        data-testid="product-thumbnail-none"
      >
        <mat-icon>image</mat-icon>
      </span>
    }
  `,
})
export class ProductThumbnail {
  private readonly auth = inject(AuthFacade);

  readonly productId = input.required<string>();
  readonly photoId = input<string | null>(null);
  /** The side drawn, in CSS pixels. */
  readonly side = input(40);
  readonly size = input<PhotoSize>('small');

  protected readonly src = computed(() => {
    const companyId = this.auth.me()?.company?.id;
    const photoId = this.photoId();
    return companyId && photoId
      ? productPhotoUrl(companyId, this.productId(), photoId, this.size())
      : null;
  });
}
