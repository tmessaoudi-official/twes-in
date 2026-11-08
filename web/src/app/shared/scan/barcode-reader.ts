// SPDX-License-Identifier: AGPL-3.0-or-later

import { Injectable } from '@angular/core';
import { ZxingBarcodeReader } from './zxing-barcode-reader';

/**
 * The port a camera frame is read through: the codes on it, each composed exactly as a handheld scanner types it, so
 * that what the camera reads enters the same path a scanner's keystrokes do. It answers for itself wherever it is
 * injected, so the first page carries no decoder: only the camera reads a frame.
 */
@Injectable({ providedIn: 'root', useFactory: () => new ZxingBarcodeReader() })
export abstract class BarcodeReader {
  abstract read(frame: ImageData): Promise<readonly string[]>;
}
