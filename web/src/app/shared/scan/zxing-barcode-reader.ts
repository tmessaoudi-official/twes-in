// SPDX-License-Identifier: AGPL-3.0-or-later

import type { BarcodeReader } from './barcode-reader';

/**
 * The camera's decoder, zxing-cpp (`zxing-decoder.ts`), which `BarcodeReader` answers with; what reads a frame is loaded
 * with the first frame. It implements the port rather than extending it, so the port can name it without a cycle.
 */
export class ZxingBarcodeReader implements BarcodeReader {
  private decoder: Promise<typeof import('./zxing-decoder')> | null = null;

  async read(frame: ImageData): Promise<readonly string[]> {
    this.decoder ??= import('./zxing-decoder');
    return (await this.decoder).readCodes(frame);
  }
}
