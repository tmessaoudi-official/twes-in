// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The port a camera frame is read through: the codes on it, each composed exactly as a handheld scanner types it, so
 * that what the camera reads enters the same path a scanner's keystrokes do.
 */
export abstract class BarcodeReader {
  abstract read(frame: ImageData): Promise<readonly string[]>;
}
