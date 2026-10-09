// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT } from '@angular/common';
import { inject, Injectable } from '@angular/core';

/** The longest side a phone sends a photo at: plenty for a product's photo, far less than a camera takes. */
export const PHOTO_LONGEST_SIDE = 2048;

/** How finely the phone's JPEG keeps the picture: no visible loss, a few hundred kilobytes. */
const QUALITY = 0.85;

/** The size a photo is sent at: within the longest side, its shape kept, never enlarged. */
export function fittedSize(width: number, height: number): { width: number; height: number } {
  const scale = Math.min(1, PHOTO_LONGEST_SIDE / Math.max(width, height));
  return {
    width: Math.max(1, Math.round(width * scale)),
    height: Math.max(1, Math.round(height * scale)),
  };
}

/**
 * A photo the phone's camera took, made fit to send: upright, within the longest side, as a JPEG. A camera takes 12
 * to 48 megapixels and several megabytes, more than a product photo may be; drawing it again also leaves behind what
 * the camera wrote in it, where the photo was taken among it. Rejects when the browser cannot read the picture.
 */
@Injectable({ providedIn: 'root' })
export class PhotoShrinker {
  private readonly document = inject(DOCUMENT);

  async shrink(photo: Blob): Promise<Blob> {
    const bitmap = await createImageBitmap(photo, { imageOrientation: 'from-image' });
    try {
      const size = fittedSize(bitmap.width, bitmap.height);
      const canvas = this.document.createElement('canvas');
      canvas.width = size.width;
      canvas.height = size.height;
      const context = canvas.getContext('2d');
      if (context === null) throw new Error('This browser draws no picture.');
      context.drawImage(bitmap, 0, 0, size.width, size.height);
      return await new Promise<Blob>((resolve, reject) =>
        canvas.toBlob(
          (blob) =>
            blob === null ? reject(new Error('The picture was not drawn.')) : resolve(blob),
          'image/jpeg',
          QUALITY,
        ),
      );
    } finally {
      bitmap.close();
    }
  }
}
