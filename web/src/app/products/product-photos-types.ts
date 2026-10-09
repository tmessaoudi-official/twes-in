// SPDX-License-Identifier: AGPL-3.0-or-later

/** One photo of a product's gallery, in the gallery's order. */
export interface ProductPhotoRow {
  id: string;
  /** The name it was sent under. */
  name: string;
  /** The type read from its bytes: image/jpeg, image/png or image/webp. */
  mime: string;
  /** The original's bytes. */
  size: number;
  /** The original's size in pixels, upright. */
  width: number;
  height: number;
  /** The photo shown wherever the product is picked or seen. */
  main: boolean;
  createdAt: string;
}

/** Which picture of a photo: the small copy for a line, the large one for a page, or the photo as it was sent. */
export type PhotoSize = 'small' | 'large' | 'original';

/**
 * Why a photo was not kept or not changed, as the screen translates it: the API's own refusal codes, and the
 * screen's for what the API said otherwise.
 */
export type ProductPhotoError =
  | 'empty'
  | 'not_a_picture'
  | 'too_large'
  | 'too_many_pixels'
  | 'too_many_photos'
  | 'changed'
  | 'not_found'
  | 'network';

export const PHOTO_REFUSALS: readonly ProductPhotoError[] = [
  'empty',
  'not_a_picture',
  'too_large',
  'too_many_pixels',
  'too_many_photos',
];

/** A refusal and what it says in numbers: the size, megapixels or count it went over. */
export interface ProductPhotoRefusal {
  code: ProductPhotoError;
  params: Readonly<Record<string, number>>;
}

/** The types a photo may be, as a file input's `accept` names them. */
export const PHOTO_TYPES = 'image/jpeg,image/png,image/webp';
