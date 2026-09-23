// SPDX-License-Identifier: AGPL-3.0-or-later

import { Injectable } from '@angular/core';
import { BarcodeReader, composeCode } from './barcode-reader';

/** Where the build puts the decoder and the licence texts of what it compiles in (web/angular.json, assets). */
export const ZXING_READER_PATH = '/vendor/zxing-reader/';

/** The symbologies a shop's goods and our own labels carry; fewer formats, faster frames. */
const FORMATS = [
  'EAN13',
  'EAN8',
  'UPCA',
  'UPCE',
  'Code128',
  'ITF',
  'Code39',
  'DataBar',
  'DataBarExp',
  'DataMatrix',
  'QRCode',
] as const;

/**
 * The camera's decoder: zxing-cpp compiled to WebAssembly, reader build only, served from our own origin and never
 * from the CDN the package defaults to (docs/SPEC.md § 7, 2026-09-22 22:38). Loaded on the first frame, so nobody who
 * never opens the camera downloads it.
 */
@Injectable()
export class ZxingBarcodeReader extends BarcodeReader {
  private module: Promise<typeof import('zxing-wasm/reader')> | null = null;

  async read(frame: ImageData): Promise<readonly string[]> {
    const zxing = await this.loaded();
    const symbols = await zxing.readBarcodes(frame, {
      formats: [...FORMATS],
      // Plain keeps a GS1 label's group separators, where the default renders "(01)…" for people.
      textMode: 'Plain',
      tryHarder: true,
      maxNumberOfSymbols: 4,
    });
    return symbols.map(composeCode).filter((code): code is string => code !== null);
  }

  private loaded(): Promise<typeof import('zxing-wasm/reader')> {
    this.module ??= import('zxing-wasm/reader').then((zxing) => {
      zxing.prepareZXingModule({
        overrides: {
          locateFile: (path: string, prefix: string) =>
            path.endsWith('.wasm') ? `${ZXING_READER_PATH}${path}` : `${prefix}${path}`,
        },
      });
      return zxing;
    });
    return this.module;
  }
}
