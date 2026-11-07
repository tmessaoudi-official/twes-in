// SPDX-License-Identifier: AGPL-3.0-or-later

import { Injectable } from '@angular/core';
import { BarcodeReader } from './barcode-reader';

/**
 * The camera's decoder, zxing-cpp (`zxing-decoder.ts`). The application provides it at its root, so this class rides
 * the first page; what reads a frame is loaded with the first frame, and the first page carries only this.
 */
@Injectable()
export class ZxingBarcodeReader extends BarcodeReader {
  private decoder: Promise<typeof import('./zxing-decoder')> | null = null;

  async read(frame: ImageData): Promise<readonly string[]> {
    this.decoder ??= import('./zxing-decoder');
    return (await this.decoder).readCodes(frame);
  }
}
