// SPDX-License-Identifier: AGPL-3.0-or-later

import type { PhotoSize } from './product-photos-types';

/**
 * Where an `<img>` reads a product photo's picture: a same-origin address the session cookie reaches. A function rather
 * than a method of the API adapter, so a screen that only draws a photo needs no HTTP client for it.
 */
export function productPhotoUrl(
  companyId: string,
  productId: string,
  photoId: string,
  size: PhotoSize,
): string {
  const product = `/api/companies/${encodeURIComponent(companyId)}/products/${encodeURIComponent(productId)}`;
  return `${product}/photos/${encodeURIComponent(photoId)}/content?size=${size}`;
}
